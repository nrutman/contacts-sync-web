<?php

namespace App\Tests\Client\PlanningCenter;

use App\Client\PlanningCenter\PlanningCenterClient;
use App\Client\WebClientFactory;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class PlanningCenterClientTest extends MockeryTestCase
{
    private const APP_ID = 'id';
    private const APP_SECRET = 'secret';
    private const EMAIL = 'foo@bar';
    private const EMAIL_ID = 1;
    private const LIST_ID = 2;
    private const LIST_NAME = 'list@list.com';
    private const PERSON_ID = 3;
    private const PERSON_FIRST = 'Joe';
    private const PERSON_LAST = 'Smith';

    /** @var MockHandler */
    private $webHandler;

    /** @var array */
    private $webHistory = [];

    /** @var PlanningCenterClient */
    private $target;

    public function setUp(): void
    {
        $this->webHandler = new MockHandler();
        $stack = HandlerStack::create($this->webHandler);
        $stack->push(Middleware::history($this->webHistory));
        $webClientFactory = new WebClientFactory(['handler' => $stack]);

        $this->target = new PlanningCenterClient(
            self::APP_ID,
            self::APP_SECRET,
            $webClientFactory,
        );
    }

    public function testGetContacts(): void
    {
        // fetch for the list
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => self::LIST_ID,
                                'attributes' => [
                                    'name' => self::LIST_NAME,
                                ],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        // fetch for the list's contacts
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'included' => [
                            [
                                'type' => 'Email',
                                'id' => self::EMAIL_ID,
                                'attributes' => [
                                    'address' => self::EMAIL,
                                ],
                            ],
                        ],
                        'data' => [
                            [
                                'id' => self::PERSON_ID,
                                'attributes' => [
                                    'first_name' => self::PERSON_FIRST,
                                    'last_name' => self::PERSON_LAST,
                                ],
                                'relationships' => [
                                    'emails' => [
                                        'data' => [
                                            [
                                                'id' => self::EMAIL_ID,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getContacts(self::LIST_NAME);

        self::assertCount(1, $result);

        $contact = $result[0];

        self::assertEquals(self::PERSON_FIRST, $contact->firstName);
        self::assertEquals(self::PERSON_LAST, $contact->lastName);
        self::assertEquals(self::EMAIL, $contact->email);

        self::assertCount(2, $this->webHistory);
    }

    public function testRefreshList(): void
    {
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => self::LIST_ID,
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $this->webHandler->append(new Response(204));

        $this->target->refreshList(self::LIST_NAME);

        self::assertCount(2, $this->webHistory);
    }

    public function testGetContactsWithPagination(): void
    {
        // fetch for the list
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => self::LIST_ID,
                                'attributes' => [
                                    'name' => self::LIST_NAME,
                                ],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        // first page with a "next" link
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'included' => [
                            [
                                'type' => 'Email',
                                'id' => self::EMAIL_ID,
                                'attributes' => [
                                    'address' => self::EMAIL,
                                ],
                            ],
                        ],
                        'data' => [
                            [
                                'id' => self::PERSON_ID,
                                'attributes' => [
                                    'first_name' => self::PERSON_FIRST,
                                    'last_name' => self::PERSON_LAST,
                                ],
                                'relationships' => [
                                    'emails' => [
                                        'data' => [
                                            [
                                                'id' => self::EMAIL_ID,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'links' => [
                            'next' => 'https://api.planningcenteronline.com/people/v2/lists/2/people?offset=25&per_page=25',
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        // second page with no "next" link
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'included' => [
                            [
                                'type' => 'Email',
                                'id' => 99,
                                'attributes' => [
                                    'address' => 'page2@test.com',
                                ],
                            ],
                        ],
                        'data' => [
                            [
                                'id' => 4,
                                'attributes' => [
                                    'first_name' => 'Jane',
                                    'last_name' => 'Doe',
                                ],
                                'relationships' => [
                                    'emails' => [
                                        'data' => [
                                            [
                                                'id' => 99,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getContacts(self::LIST_NAME);

        self::assertCount(2, $result);
        self::assertEquals(self::EMAIL, $result[0]->email);
        self::assertEquals('page2@test.com', $result[1]->email);
        // list lookup + page 1 + page 2
        self::assertCount(3, $this->webHistory);
    }

    public function testGetContactsSkipsPersonWithoutEmail(): void
    {
        // fetch for the list
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => self::LIST_ID,
                                'attributes' => [
                                    'name' => self::LIST_NAME,
                                ],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        // person with no emails
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'included' => [],
                        'data' => [
                            [
                                'id' => self::PERSON_ID,
                                'attributes' => [
                                    'first_name' => self::PERSON_FIRST,
                                    'last_name' => self::PERSON_LAST,
                                ],
                                'relationships' => [
                                    'emails' => [
                                        'data' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getContacts(self::LIST_NAME);

        self::assertCount(0, $result);
    }

    public function testGetContactsListNotFound(): void
    {
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(
            'The list `list@list.com` could not be found.',
        );

        $this->target->getContacts(self::LIST_NAME);
    }

    public function testGetAvailableLists(): void
    {
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => 1,
                                'attributes' => ['name' => 'Church Members'],
                            ],
                            [
                                'id' => 2,
                                'attributes' => ['name' => 'Volunteers'],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getAvailableLists();

        self::assertEquals(
            ['Church Members' => 'Church Members', 'Volunteers' => 'Volunteers'],
            $result,
        );
        self::assertCount(1, $this->webHistory);
    }

    public function testGetAvailableListsEmpty(): void
    {
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    ['data' => []],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getAvailableLists();

        self::assertSame([], $result);
    }

    public function testGetAvailableListsWithPagination(): void
    {
        // First page with a "next" link
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => 1,
                                'attributes' => ['name' => 'List A'],
                            ],
                        ],
                        'links' => [
                            'next' => 'https://api.planningcenteronline.com/people/v2/lists?offset=100&per_page=100',
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        // Second page with no "next" link
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => 2,
                                'attributes' => ['name' => 'List B'],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getAvailableLists();

        self::assertEquals(
            ['List A' => 'List A', 'List B' => 'List B'],
            $result,
        );
        self::assertCount(2, $this->webHistory);
    }

    public function testRefreshListListNotFound(): void
    {
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(
            'The list `list@list.com` could not be found.',
        );

        $this->target->refreshList(self::LIST_NAME);
    }

    public function testGetContactsThrowsOnHttpServerError(): void
    {
        // List lookup returns 500
        $this->webHandler->append(
            new ServerException(
                'Server error',
                new GuzzleRequest('GET', '/people/v2/lists'),
                new Response(500),
            ),
        );

        $this->expectException(ServerException::class);

        $this->target->getContacts(self::LIST_NAME);
    }

    public function testGetContactsThrowsOnHttpClientError(): void
    {
        // List lookup returns 401 (auth failure)
        $this->webHandler->append(
            new ClientException(
                'Unauthorized',
                new GuzzleRequest('GET', '/people/v2/lists'),
                new Response(401),
            ),
        );

        $this->expectException(ClientException::class);

        $this->target->getContacts(self::LIST_NAME);
    }

    public function testGetContactsThrowsOnMalformedJson(): void
    {
        $this->webHandler->append(new Response(200, [], '{not valid json'));

        $this->expectException(\JsonException::class);

        $this->target->getContacts(self::LIST_NAME);
    }

    public function testGetContactsThrowsOnPeoplePageHttpError(): void
    {
        // List lookup succeeds
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => self::LIST_ID,
                                'attributes' => ['name' => self::LIST_NAME],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        // People page errors with 500
        $this->webHandler->append(
            new ServerException(
                'Server error',
                new GuzzleRequest('GET', '/people/v2/lists/2/people'),
                new Response(500),
            ),
        );

        $this->expectException(ServerException::class);

        $this->target->getContacts(self::LIST_NAME);
    }

    public function testGetContactsHandlesEmptyPage(): void
    {
        // List lookup
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => self::LIST_ID,
                                'attributes' => ['name' => self::LIST_NAME],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        // People page is empty (no contacts, no next link)
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'included' => [],
                        'data' => [],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getContacts(self::LIST_NAME);

        self::assertSame([], $result);
    }

    public function testGetContactsUsesPrimaryEmailWhenMultipleProvided(): void
    {
        // The client takes the first email in the relationships list, which by
        // PCO convention is the primary. Verify multiple emails do not break
        // hydration and the first ID wins.
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => self::LIST_ID,
                                'attributes' => ['name' => self::LIST_NAME],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'included' => [
                            [
                                'type' => 'Email',
                                'id' => 1,
                                'attributes' => ['address' => 'primary@x.com'],
                            ],
                            [
                                'type' => 'Email',
                                'id' => 2,
                                'attributes' => ['address' => 'secondary@x.com'],
                            ],
                        ],
                        'data' => [
                            [
                                'id' => self::PERSON_ID,
                                'attributes' => [
                                    'first_name' => self::PERSON_FIRST,
                                    'last_name' => self::PERSON_LAST,
                                ],
                                'relationships' => [
                                    'emails' => [
                                        'data' => [
                                            ['id' => 1],
                                            ['id' => 2],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getContacts(self::LIST_NAME);

        self::assertCount(1, $result);
        self::assertEquals('primary@x.com', $result[0]->email);
    }

    public function testGetContactsIgnoresNonEmailIncluded(): void
    {
        // Non-Email entries in `included` (e.g. PhoneNumber) should be filtered
        // out of the email map. A person whose email reference points to a
        // non-Email type would resolve to a null entry and be dropped.
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => self::LIST_ID,
                                'attributes' => ['name' => self::LIST_NAME],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'included' => [
                            [
                                'type' => 'PhoneNumber',
                                'id' => self::EMAIL_ID,
                                'attributes' => ['number' => '555-1234'],
                            ],
                            [
                                'type' => 'Email',
                                'id' => 99,
                                'attributes' => ['address' => self::EMAIL],
                            ],
                        ],
                        'data' => [
                            [
                                'id' => self::PERSON_ID,
                                'attributes' => [
                                    'first_name' => self::PERSON_FIRST,
                                    'last_name' => self::PERSON_LAST,
                                ],
                                'relationships' => [
                                    'emails' => [
                                        'data' => [['id' => 99]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getContacts(self::LIST_NAME);

        self::assertCount(1, $result);
        self::assertEquals(self::EMAIL, $result[0]->email);
    }

    public function testGetAvailableListsThrowsOnHttpError(): void
    {
        $this->webHandler->append(
            new ServerException(
                'Server error',
                new GuzzleRequest('GET', '/people/v2/lists'),
                new Response(500),
            ),
        );

        $this->expectException(ServerException::class);

        $this->target->getAvailableLists();
    }

    public function testGetAvailableListsThrowsOnMalformedJson(): void
    {
        $this->webHandler->append(new Response(200, [], 'not-json-at-all'));

        $this->expectException(\JsonException::class);

        $this->target->getAvailableLists();
    }

    public function testRefreshListThrowsWhenRunPostFails(): void
    {
        // List lookup succeeds
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            ['id' => self::LIST_ID],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        // POST /run returns 500
        $this->webHandler->append(
            new ServerException(
                'Run failed',
                new GuzzleRequest('POST', '/people/v2/lists/2/run'),
                new Response(500),
            ),
        );

        $this->expectException(ServerException::class);

        $this->target->refreshList(self::LIST_NAME);
    }

    public function testGetContactsCaseInsensitiveListMatch(): void
    {
        // PCO does an `iLIKE` filter server-side; the client also applies a
        // case-insensitive regex. Verify that a list returned with different
        // casing is still matched.
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'data' => [
                            [
                                'id' => self::LIST_ID,
                                'attributes' => ['name' => 'LIST@list.com'],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'included' => [],
                        'data' => [],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getContacts(self::LIST_NAME);

        self::assertSame([], $result);
        self::assertCount(2, $this->webHistory);
    }
}
