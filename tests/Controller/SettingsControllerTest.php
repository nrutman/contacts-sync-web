<?php

namespace App\Tests\Controller;

use App\Controller\SettingsController;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Form\OrganizationType;
use App\Repository\OrganizationRepository;
use App\Repository\ProviderCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mockery as m;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class SettingsControllerTest extends AbstractControllerTestCase
{
    private OrganizationRepository|m\LegacyMockInterface $organizationRepository;
    private ProviderCredentialRepository|m\LegacyMockInterface $credentialRepository;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;

    private SettingsController $controller;

    protected function setUp(): void
    {
        $this->organizationRepository = m::mock(OrganizationRepository::class);
        $this->credentialRepository = m::mock(ProviderCredentialRepository::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);

        $this->controller = new SettingsController(
            $this->organizationRepository,
            $this->credentialRepository,
            $this->entityManager,
        );

        $this->controller->setContainer($this->buildBaseContainer());
    }

    public function testIndexCreatesNewOrganizationFormWhenNoneExists(): void
    {
        $this->organizationRepository->shouldReceive('findOne')->andReturn(null);

        $form = $this->mockForm(OrganizationType::class, ['is_edit' => false], submitted: false);

        $captured = [];
        $this->expectRender('settings/index.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $response = $this->controller->index(new Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($captured['organization']);
        $this->assertFalse($captured['is_edit']);
        $this->assertSame([], $captured['credentials']);
    }

    public function testIndexLoadsCredentialsWhenOrganizationExists(): void
    {
        $org = new Organization();
        $org->setName('Existing');

        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);

        $cred = new ProviderCredential();
        $cred->setOrganization($org);
        $cred->setProviderName('mailchimp');
        $this->credentialRepository->shouldReceive('findByOrganization')->with($org)->andReturn([$cred]);

        $this->mockForm(OrganizationType::class, ['is_edit' => true], submitted: false);

        $captured = [];
        $this->expectRender('settings/index.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $this->controller->index(new Request());

        $this->assertSame($org, $captured['organization']);
        $this->assertTrue($captured['is_edit']);
        $this->assertSame([$cred], $captured['credentials']);
    }

    public function testIndexPersistsNewOrganizationOnValidSubmit(): void
    {
        $this->organizationRepository->shouldReceive('findOne')->andReturn(null);

        $this->mockForm(OrganizationType::class, ['is_edit' => false], submitted: true, valid: true);

        $this->entityManager->shouldReceive('persist')->once();
        $this->entityManager->shouldReceive('flush')->once();

        $this->expectRoute('app_settings', '/settings');

        $response = $this->controller->index(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings', $response->getTargetUrl());
        $this->assertNotEmpty($this->flashes('success'));
    }

    public function testIndexUpdatesExistingOrganizationOnValidSubmit(): void
    {
        $org = new Organization();
        $org->setName('Existing');

        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);
        $this->mockForm(OrganizationType::class, ['is_edit' => true], submitted: true, valid: true);

        // Expect flush only — no persist on edit
        $this->entityManager->shouldNotReceive('persist');
        $this->entityManager->shouldReceive('flush')->once();

        $this->expectRoute('app_settings', '/settings');

        $response = $this->controller->index(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * Mocks createForm() returning a configured FormInterface.
     */
    private function mockForm(
        string $type,
        array $options,
        bool $submitted,
        bool $valid = false,
    ): FormInterface|m\LegacyMockInterface {
        $form = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest');
        $form->shouldReceive('isSubmitted')->andReturn($submitted);
        if ($submitted) {
            $form->shouldReceive('isValid')->andReturn($valid);
        }
        $form->shouldReceive('createView')->andReturn(new FormView());

        $this->formFactory->shouldReceive('create')
            ->with($type, m::any(), m::on(fn (array $opts) => $opts === $options || array_intersect_key($opts, $options) === $options))
            ->andReturn($form);

        return $form;
    }
}
