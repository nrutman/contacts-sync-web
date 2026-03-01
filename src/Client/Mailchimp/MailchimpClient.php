<?php

namespace App\Client\Mailchimp;

use App\Client\ReadableListClientInterface;
use App\Client\WebClientFactoryInterface;
use App\Client\WriteableListClientInterface;
use App\Contact\Contact;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class MailchimpClient implements ReadableListClientInterface, WriteableListClientInterface
{
    private ClientInterface $webClient;

    public function __construct(
        string $apiKey,
        WebClientFactoryInterface $webClientFactory,
    ) {
        $dc = self::extractDataCenter($apiKey);

        $this->webClient = $webClientFactory->create([
            'base_uri' => sprintf('https://%s.api.mailchimp.com/3.0/', $dc),
            'auth' => ['anystring', $apiKey],
        ]);
    }

    /**
     * @return Contact[]
     *
     * @throws GuzzleException
     */
    public function getContacts(string $listName): array
    {
        $contacts = [];
        $offset = 0;
        $count = 1000;

        do {
            $response = $this->webClient->request('GET', sprintf('/3.0/lists/%s/members', $listName), [
                'query' => [
                    'status' => 'subscribed',
                    'count' => $count,
                    'offset' => $offset,
                    'fields' => 'members.email_address,members.merge_fields,total_items',
                ],
            ]);

            $data = json_decode(
                $response->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $totalItems = $data['total_items'];

            foreach ($data['members'] as $member) {
                $contact = new Contact();
                $contact->email = $member['email_address'];

                $firstName = $member['merge_fields']['FNAME'] ?? null;
                $contact->firstName = ($firstName !== null && $firstName !== '') ? $firstName : null;

                $lastName = $member['merge_fields']['LNAME'] ?? null;
                $contact->lastName = ($lastName !== null && $lastName !== '') ? $lastName : null;

                $contacts[] = $contact;
            }

            $offset += $count;
        } while (count($contacts) < $totalItems);

        return $contacts;
    }

    /**
     * Adds (or re-subscribes) a contact to an audience using PUT (upsert).
     *
     * @throws GuzzleException
     */
    public function addContact(string $list, Contact $contact): void
    {
        $body = [
            'email_address' => $contact->email,
            'status' => 'subscribed',
        ];

        $mergeFields = [];
        if ($contact->firstName !== null) {
            $mergeFields['FNAME'] = $contact->firstName;
        }
        if ($contact->lastName !== null) {
            $mergeFields['LNAME'] = $contact->lastName;
        }
        if ($mergeFields !== []) {
            $body['merge_fields'] = $mergeFields;
        }

        $hash = self::subscriberHash($contact->email);

        $this->webClient->request('PUT', sprintf('/3.0/lists/%s/members/%s', $list, $hash), [
            'json' => $body,
        ]);
    }

    /**
     * Unsubscribes a contact from an audience.
     *
     * @throws GuzzleException
     */
    public function removeContact(string $list, Contact $contact): void
    {
        $hash = self::subscriberHash($contact->email);

        $this->webClient->request('PATCH', sprintf('/3.0/lists/%s/members/%s', $list, $hash), [
            'json' => [
                'status' => 'unsubscribed',
            ],
        ]);
    }

    /**
     * Returns available audiences (lists) as an [id => name] map.
     *
     * @return array<string, string>
     *
     * @throws GuzzleException
     */
    public function getAvailableLists(): array
    {
        $response = $this->webClient->request('GET', '/3.0/lists', [
            'query' => [
                'count' => 100,
                'fields' => 'lists.id,lists.name,total_items',
            ],
        ]);

        $data = json_decode(
            $response->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $lists = [];
        foreach ($data['lists'] as $list) {
            $lists[$list['id']] = $list['name'];
        }

        return $lists;
    }

    /**
     * Extracts the data center from a Mailchimp API key.
     *
     * API keys follow the format "{key}-{dc}" (e.g. "abc123def-us21").
     */
    public static function extractDataCenter(string $apiKey): string
    {
        $parts = explode('-', $apiKey);

        if (count($parts) < 2 || $parts[count($parts) - 1] === '') {
            throw new \InvalidArgumentException('Invalid Mailchimp API key format. Expected format: {key}-{dc} (e.g. "abc123-us21").');
        }

        return $parts[count($parts) - 1];
    }

    /**
     * Returns the MD5 hash of a lowercased email address, used as the subscriber identifier in Mailchimp's API.
     */
    public static function subscriberHash(string $email): string
    {
        return md5(strtolower($email));
    }
}
