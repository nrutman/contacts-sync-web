<?php

namespace App\Tests\Controller;

use App\Controller\ManualContactController;
use App\Entity\ManualContact;
use App\Entity\Organization;
use App\Form\ManualContactType;
use App\Repository\ManualContactRepository;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mockery as m;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;

class ManualContactControllerTest extends AbstractControllerTestCase
{
    private OrganizationRepository|m\LegacyMockInterface $organizationRepository;
    private ManualContactRepository|m\LegacyMockInterface $manualContactRepository;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;

    private ManualContactController $controller;

    protected function setUp(): void
    {
        $this->organizationRepository = m::mock(OrganizationRepository::class);
        $this->manualContactRepository = m::mock(ManualContactRepository::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);

        $this->controller = new ManualContactController(
            $this->organizationRepository,
            $this->manualContactRepository,
            $this->entityManager,
        );

        $this->controller->setContainer($this->buildBaseContainer());
    }

    public function testIndexRedirectsToSettingsWhenNoOrganization(): void
    {
        $this->organizationRepository->shouldReceive('findOne')->andReturn(null);
        $this->expectRoute('app_settings', '/settings');

        $response = $this->controller->index();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings', $response->getTargetUrl());
    }

    public function testIndexLoadsContactsForOrganization(): void
    {
        $org = new Organization();
        $org->setName('Org');
        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);

        $contact = $this->makeContact($org);
        $this->manualContactRepository->shouldReceive('findBy')
            ->with(['organization' => $org], ['email' => 'ASC'])
            ->andReturn([$contact]);

        $captured = [];
        $this->expectRender('manual_contact/index.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $response = $this->controller->index();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$contact], $captured['contacts']);
    }

    public function testNewRedirectsToSettingsWhenNoOrganization(): void
    {
        $this->organizationRepository->shouldReceive('findOne')->andReturn(null);
        $this->expectRoute('app_settings', '/settings');

        $response = $this->controller->new(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings', $response->getTargetUrl());
    }

    public function testNewPersistsContactWithOrganizationOnValidSubmit(): void
    {
        $org = new Organization();
        $org->setName('Org');
        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);

        $this->mockForm(
            ManualContactType::class,
            true,
            true,
            populateData: function (ManualContact $c): void {
                $c->setName('John Doe');
                $c->setEmail('jd@example.com');
            },
        );

        $captured = null;
        $this->entityManager->shouldReceive('persist')
            ->with(m::on(function (ManualContact $c) use (&$captured): bool {
                $captured = $c;

                return true;
            }))
            ->once();
        $this->entityManager->shouldReceive('flush')->once();

        $this->expectRoute('app_contact_index', '/contacts');

        $response = $this->controller->new(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame($org, $captured->getOrganization());
        $this->assertNotEmpty($this->flashes('success'));
    }

    public function testEditFlushesOnValidSubmit(): void
    {
        $org = new Organization();
        $org->setName('Org');
        $contact = $this->makeContact($org);

        $this->mockForm(ManualContactType::class, true, true);
        $this->entityManager->shouldReceive('flush')->once();
        $this->expectRoute('app_contact_index', '/contacts');

        $response = $this->controller->edit(new Request(), $contact);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->flashes('success'));
    }

    public function testDeleteRemovesContactOnValidCsrf(): void
    {
        $org = new Organization();
        $org->setName('Org');
        $contact = $this->makeContact($org);

        $this->csrfTokenManager->shouldReceive('isTokenValid')
            ->with(m::on(fn (CsrfToken $t) => $t->getId() === 'delete-contact-'.$contact->getId() && $t->getValue() === 'good'))
            ->andReturn(true);

        $this->entityManager->shouldReceive('remove')->with($contact)->once();
        $this->entityManager->shouldReceive('flush')->once();
        $this->expectRoute('app_contact_index', '/contacts');

        $request = Request::create('/contacts/'.$contact->getId(), 'DELETE', ['_token' => 'good']);

        $response = $this->controller->delete($request, $contact);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->flashes('success'));
    }

    public function testDeleteIgnoresInvalidCsrf(): void
    {
        $org = new Organization();
        $org->setName('Org');
        $contact = $this->makeContact($org);

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(false);
        $this->entityManager->shouldNotReceive('remove');
        $this->expectRoute('app_contact_index', '/contacts');

        $request = Request::create('/contacts/'.$contact->getId(), 'DELETE', ['_token' => 'bad']);

        $response = $this->controller->delete($request, $contact);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame([], $this->flashes('success'));
    }

    private function makeContact(Organization $org): ManualContact
    {
        $contact = new ManualContact();
        $contact->setOrganization($org);
        $contact->setName('Jane');
        $contact->setEmail('jane@example.com');

        return $contact;
    }

    /**
     * @return FormInterface&m\LegacyMockInterface
     */
    private function mockForm(
        string $type,
        bool $submitted,
        bool $valid = false,
        ?\Closure $populateData = null,
    ): FormInterface {
        $form = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest');
        $form->shouldReceive('isSubmitted')->andReturn($submitted);
        if ($submitted) {
            $form->shouldReceive('isValid')->andReturn($valid);
        }
        $form->shouldReceive('createView')->andReturn(new FormView());

        $this->formFactory->shouldReceive('create')
            ->andReturnUsing(function (string $type, mixed $data, array $opts) use ($form, $populateData) {
                if ($populateData !== null && $data !== null) {
                    $populateData($data);
                }

                return $form;
            });

        return $form;
    }
}
