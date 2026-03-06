<?php

namespace App\Tests\Entity;

use App\Entity\Organization;
use App\Entity\ProviderCredential;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class ProviderCredentialTest extends MockeryTestCase
{
    public function testDefaultState(): void
    {
        $credential = new ProviderCredential();

        self::assertNotNull($credential->getId());
        self::assertEquals('{}', $credential->getCredentials());
        self::assertNull($credential->getLabel());
        self::assertNull($credential->getMetadata());
        self::assertNotNull($credential->getCreatedAt());
        self::assertNotNull($credential->getUpdatedAt());
    }

    public function testSettersAndGetters(): void
    {
        $org = new Organization();
        $org->setName('Test');

        $credential = new ProviderCredential();
        $credential->setOrganization($org);
        $credential->setProviderName('planning_center');
        $credential->setLabel('My PC Account');
        $credential->setCredentials('{"key":"value"}');

        self::assertSame($org, $credential->getOrganization());
        self::assertEquals('planning_center', $credential->getProviderName());
        self::assertEquals('My PC Account', $credential->getLabel());
        self::assertEquals('{"key":"value"}', $credential->getCredentials());
    }

    public function testCredentialsArray(): void
    {
        $credential = new ProviderCredential();
        $credential->setCredentialsArray(['app_id' => 'test', 'app_secret' => 's3cret']);

        $data = $credential->getCredentialsArray();
        self::assertEquals('test', $data['app_id']);
        self::assertEquals('s3cret', $data['app_secret']);
    }

    public function testMetadataHelpers(): void
    {
        $credential = new ProviderCredential();
        self::assertNull($credential->getMetadataValue('key'));
        self::assertEquals('default', $credential->getMetadataValue('key', 'default'));

        $credential->setMetadataValue('domain', 'example.com');
        self::assertEquals('example.com', $credential->getMetadataValue('domain'));

        $credential->setMetadata(['a' => 1, 'b' => 2]);
        self::assertEquals(['a' => 1, 'b' => 2], $credential->getMetadata());
    }

    public function testDisplayLabelFallsBackToProviderName(): void
    {
        $credential = new ProviderCredential();
        $credential->setProviderName('google_groups');

        self::assertEquals('google_groups', $credential->getDisplayLabel());
        self::assertEquals('google_groups', (string) $credential);

        $credential->setLabel('Main Google');
        self::assertEquals('Main Google', $credential->getDisplayLabel());
        self::assertEquals('Main Google', (string) $credential);
    }

    public function testUpdateTimestamp(): void
    {
        $credential = new ProviderCredential();
        $originalUpdatedAt = $credential->getUpdatedAt();

        // Simulate a PreUpdate lifecycle callback
        usleep(1000);
        $credential->updateTimestamp();

        self::assertGreaterThanOrEqual($originalUpdatedAt, $credential->getUpdatedAt());
    }
}
