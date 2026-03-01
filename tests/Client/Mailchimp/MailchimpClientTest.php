<?php

namespace App\Tests\Client\Mailchimp;

use App\Client\Mailchimp\MailchimpClient;
use App\Client\WebClientFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class MailchimpClientTest extends MockeryTestCase
{
    private const API_KEY = 'abc123def456-us21';
    private const LIST_ID = 'list123';
    private const EMAIL = 'joe@example.com';
    private const FIRST_NAME = 'Joe';
    private const LAST_NAME = 'Smith';

    private MockHandler $webHandler;
    private array $webHistory = [];
    private MailchimpClient $target;

    protected function setUp(): void
    {
        $this->webHandler = new MockHandler();
        $stack = HandlerStack::create($this->webHandler);
        $stack->push(Middleware::history($this->webHistory));
        $webClientFactory = new WebClientFactory(['handler' => $stack]);

        $this->target = new MailchimpClient(
            self::API_KEY,
            $webClientFactory,
        );
    }

    public function testGetContacts(): void
    {
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'members' => [
                            [
                                'email_address' => self::EMAIL,
                                'merge_fields' => [
                                    'FNAME' => self::FIRST_NAME,
                                    'LNAME' => self::LAST_NAME,
                                ],
                            ],
                        ],
                        'total_items' => 1,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getContacts(self::LIST_ID);

        self::assertCount(1, $result);
        self::assertEquals(self::EMAIL, $result[0]->email);
        self::assertEquals(self::FIRST_NAME, $result[0]->firstName);
        self::assertEquals(self::LAST_NAME, $result[0]->lastName);
        self::assertCount(1, $this->webHistory);
    }

    public function testGetContactsWithPagination(): void
    {
        // First page — total_items indicates more than one page
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'members' => [
                            [
                                'email_address' => 'page1@example.com',
                                'merge_fields' => [
                                    'FNAME' => 'Page',
                                    'LNAME' => 'One',
                                ],
                            ],
                        ],
                        'total_items' => 2,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        // Second page
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'members' => [
                            [
                                'email_address' => 'page2@example.com',
                                'merge_fields' => [
                                    'FNAME' => 'Page',
                                    'LNAME' => 'Two',
                                ],
                            ],
                        ],
                        'total_items' => 2,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getContacts(self::LIST_ID);

        self::assertCount(2, $result);
        self::assertEquals('page1@example.com', $result[0]->email);
        self::assertEquals('page2@example.com', $result[1]->email);
        self::assertCount(2, $this->webHistory);
    }

    public function testGetContactsEmptyAudience(): void
    {
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'members' => [],
                        'total_items' => 0,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getContacts(self::LIST_ID);

        self::assertCount(0, $result);
    }

    public function testGetContactsHandlesEmptyMergeFields(): void
    {
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'members' => [
                            [
                                'email_address' => self::EMAIL,
                                'merge_fields' => [
                                    'FNAME' => '',
                                    'LNAME' => '',
                                ],
                            ],
                        ],
                        'total_items' => 1,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getContacts(self::LIST_ID);

        self::assertCount(1, $result);
        self::assertEquals(self::EMAIL, $result[0]->email);
        self::assertNull($result[0]->firstName);
        self::assertNull($result[0]->lastName);
    }

    public function testAddContact(): void
    {
        $this->webHandler->append(new Response(200));

        $contact = new \App\Contact\Contact();
        $contact->email = self::EMAIL;
        $contact->firstName = self::FIRST_NAME;
        $contact->lastName = self::LAST_NAME;

        $this->target->addContact(self::LIST_ID, $contact);

        self::assertCount(1, $this->webHistory);

        $request = $this->webHistory[0]['request'];
        $expectedHash = md5(strtolower(self::EMAIL));
        self::assertStringContainsString(
            sprintf('/3.0/lists/%s/members/%s', self::LIST_ID, $expectedHash),
            (string) $request->getUri(),
        );
        self::assertEquals('PUT', $request->getMethod());

        $body = json_decode($request->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals('subscribed', $body['status']);
        self::assertEquals(self::EMAIL, $body['email_address']);
        self::assertEquals(self::FIRST_NAME, $body['merge_fields']['FNAME']);
        self::assertEquals(self::LAST_NAME, $body['merge_fields']['LNAME']);
    }

    public function testAddContactWithoutName(): void
    {
        $this->webHandler->append(new Response(200));

        $contact = new \App\Contact\Contact();
        $contact->email = self::EMAIL;

        $this->target->addContact(self::LIST_ID, $contact);

        $request = $this->webHistory[0]['request'];
        $body = json_decode($request->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('merge_fields', $body);
    }

    public function testRemoveContact(): void
    {
        $this->webHandler->append(new Response(200));

        $contact = new \App\Contact\Contact();
        $contact->email = self::EMAIL;

        $this->target->removeContact(self::LIST_ID, $contact);

        self::assertCount(1, $this->webHistory);

        $request = $this->webHistory[0]['request'];
        $expectedHash = md5(strtolower(self::EMAIL));
        self::assertStringContainsString(
            sprintf('/3.0/lists/%s/members/%s', self::LIST_ID, $expectedHash),
            (string) $request->getUri(),
        );
        self::assertEquals('PATCH', $request->getMethod());

        $body = json_decode($request->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals('unsubscribed', $body['status']);
    }

    public function testGetAvailableLists(): void
    {
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'lists' => [
                            ['id' => 'abc123', 'name' => 'Newsletter'],
                            ['id' => 'def456', 'name' => 'Members'],
                        ],
                        'total_items' => 2,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );

        $result = $this->target->getAvailableLists();

        self::assertEquals(['abc123' => 'Newsletter', 'def456' => 'Members'], $result);
    }

    public function testInvalidApiKeyFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MailchimpClient(
            'invalid-key-without-dc-',
            new WebClientFactory(),
        );
    }

    public function testInvalidApiKeyFormatNoDash(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MailchimpClient(
            'invalidkeywithoutdc',
            new WebClientFactory(),
        );
    }

    public function testDataCenterExtraction(): void
    {
        $this->webHandler->append(
            new Response(
                200,
                [],
                json_encode(['lists' => [], 'total_items' => 0], JSON_THROW_ON_ERROR),
            ),
        );

        // The client was created with API key ending in "us21", verify the base URI
        $this->target->getAvailableLists();

        $request = $this->webHistory[0]['request'];
        self::assertStringContainsString('us21.api.mailchimp.com', (string) $request->getUri());
    }
}
