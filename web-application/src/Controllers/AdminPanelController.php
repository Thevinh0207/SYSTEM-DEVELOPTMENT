<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AboutModel;
use App\Models\AppointmentModel;
use App\Models\FaqModel;
use App\Models\PaymentModel;
use App\Models\ReviewModel;
use App\Models\ServiceCategoryModel;
use App\Models\ServiceModel;
use App\Models\UserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;
use Throwable;
use Twig\Environment;

class AdminPanelController extends BaseController
{
    public function __construct(
        Environment $twig,
        string $basePath,
        private AppointmentModel $appointments,
        private ServiceModel $services,
        private ReviewModel $reviews,
        private PaymentModel $payments,
        private FaqModel $faqs,
        private AboutModel $about,
        private ServiceCategoryModel $serviceCats
    ) {
        parent::__construct($twig, $basePath);
    }

    // ── Dashboard view ───────────────────────────────────────────────────
    public function dashboard(Request $r, Response $response, array $args = []): Response
    {
        if ($block = $this->guard()) return $block;

        $section = (string) ($args['section'] ?? 'appointments');
        $flash   = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);

        try {
            $data = [
                'appointments' => $this->loadAppointmentRows(),
                'services'     => $this->loadServiceRows(),
                'reviews'      => $this->loadReviewRows(),
                'payments'     => $this->loadPaymentRows(),
                'insights'     => $this->buildInsights(),
                'faqs'         => $this->faqs->findAll(),
                'aboutSections'=> $this->about->findAll(),
                'serviceCategories' => $this->serviceCats->findAll(),
            ];
        } catch (Throwable $e) {
            $data = ['appointments' => [], 'services' => [], 'reviews' => [], 'payments' => [], 'insights' => [], 'faqs' => [], 'aboutSections' => [], 'serviceCategories' => []];
        }

        return $this->render($response, 'admin.twig', [
            'section' => $section,
            'flash'   => $flash,
        ] + $data);
    }

    // ── Services CRUD ────────────────────────────────────────────────────
    public function newService(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;
        $form   = $_SESSION['admin_form']   ?? [];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);
        return $this->render($response, 'admin/service-form.twig', [
            'mode' => 'create', 'service' => $form, 'errors' => $errors,
            'categories' => $this->serviceCats->findAll(),
        ]);
    }

    public function createService(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;

        $data = $this->extractServiceForm($r);
        if ($errors = $this->validateService($data)) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect('/admin/services/new');
        }

        try {
            $this->services->create($data);
            $this->flash('success', 'Service created.');
        } catch (Throwable $e) {
            $this->flash('error', 'Could not create service.');
        }
        return $this->redirect('/admin/services');
    }

    public function editService(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $id      = (int) $args['id'];
        $service = $this->services->load($id);
        if (!$service) {
            $this->flash('error', 'Service not found.');
            return $this->redirect('/admin/services');
        }

        $form = $_SESSION['admin_form'] ?? [
            'name'        => $service->name,
            'categoryId'  => (int) $service->categoryId,
            'description' => $service->description,
            'price'       => $service->price,
            'duration'    => $service->duration,
        ];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);

        return $this->render($response, 'admin/service-form.twig', [
            'mode' => 'edit', 'id' => $id, 'service' => $form, 'errors' => $errors,
            'categories' => $this->serviceCats->findAll(),
        ]);
    }

    public function updateService(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $id   = (int) $args['id'];
        $data = $this->extractServiceForm($r);
        if ($errors = $this->validateService($data)) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect("/admin/services/{$id}/edit");
        }

        try {
            $service = $this->services->load($id);
            if (!$service) {
                $this->flash('error', 'Service not found.');
            } else {
                $service->name        = $data['name'];
                $service->categoryId  = (int) $data['categoryId'];
                $service->description = $data['description'];
                $service->price       = (float) $data['price'];
                $service->duration    = (int)   $data['duration'];
                $this->services->save($service);
                $this->flash('success', 'Service updated.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not update service.');
        }
        return $this->redirect('/admin/services');
    }

    public function deleteService(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        try {
            $service = $this->services->load((int) $args['id']);
            if ($service) {
                $this->services->delete($service);
                $this->flash('success', 'Service deleted.');
            } else {
                $this->flash('error', 'Service not found.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Cannot delete: this service is used by existing appointments.');
        }
        return $this->redirect('/admin/services');
    }

    // ── Appointments: create / edit / cancel ─────────────────────────────
    public function newAppointment(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;

        $form   = $_SESSION['admin_form']   ?? ['status' => 'pending'];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);

        return $this->render($response, 'admin/appointment-form.twig', [
            'mode'        => 'create',
            'appointment' => $form,
            'services'    => $this->loadServiceRows(),
            'errors'      => $errors,
        ]);
    }

    public function createAppointment(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;

        $body = (array) $r->getParsedBody();
        $data = [
            'serviceID'  => (int) ($body['serviceID'] ?? 0),
            'date'       => trim((string) ($body['date']        ?? '')),
            'time'       => trim((string) ($body['time']        ?? '')),
            'notes'      => trim((string) ($body['notes']       ?? '')),
            'status'     => trim((string) ($body['status']      ?? 'pending')),
            'guestName'  => trim((string) ($body['guestName']   ?? '')),
            'guestEmail' => trim((string) ($body['guestEmail']  ?? '')),
            'guestPhone' => trim((string) ($body['guestPhone']  ?? '')),
        ];

        $errors = [];
        if ($data['serviceID'] <= 0) $errors['serviceID'] = 'Service is required.';
        if ($data['date'] === '')    $errors['date']      = 'Date is required.';
        if ($data['time'] === '')    $errors['time']      = 'Time is required.';
        if (!in_array($data['status'], ['pending','confirmed','completed','cancelled'], true)) {
            $errors['status'] = 'Invalid status.';
        }
        if ($data['guestName'] === '') {
            $errors['guestName'] = 'Customer name is required.';
        }
        if ($data['guestEmail'] === '' && $data['guestPhone'] === '') {
            $errors['guestEmail'] = 'Provide at least an email or phone.';
        }
        if ($errors) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect('/admin/appointments/new');
        }

        try {
            $appt = $this->appointments->create($data);
            if ($appt) {
                $this->flash('success', 'Appointment #' . $appt->id . ' created.');
            } else {
                $this->flash('error', 'That time slot is already booked. Pick another time.');
                $_SESSION['admin_form'] = $data;
                return $this->redirect('/admin/appointments/new');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not create appointment.');
            $_SESSION['admin_form'] = $data;
            return $this->redirect('/admin/appointments/new');
        }
        return $this->redirect('/admin/appointments');
    }

    public function editAppointment(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $id   = (int) $args['id'];
        $appt = $this->appointments->load($id);
        if (!$appt) {
            $this->flash('error', 'Appointment not found.');
            return $this->redirect('/admin');
        }

        $form = $_SESSION['admin_form'] ?? [
            'serviceID' => (int) $appt->serviceID,
            'date'      => $appt->date,
            'time'      => $appt->time,
            'notes'     => $appt->notes,
            'status'    => $appt->status,
        ];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);

        return $this->render($response, 'admin/appointment-form.twig', [
            'mode'        => 'edit',
            'id'          => $id,
            'appointment' => $form,
            'services'    => $this->loadServiceRows(),
            'errors'      => $errors,
        ]);
    }

    public function updateAppointment(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $id   = (int) $args['id'];
        $body = (array) $r->getParsedBody();
        $data = [
            'serviceID' => (int) ($body['serviceID'] ?? 0),
            'date'      => trim((string) ($body['date']   ?? '')),
            'time'      => trim((string) ($body['time']   ?? '')),
            'notes'     => trim((string) ($body['notes']  ?? '')),
            'status'    => trim((string) ($body['status'] ?? '')),
        ];

        $errors = [];
        if ($data['serviceID'] <= 0)   $errors['serviceID'] = 'Service is required.';
        if ($data['date'] === '')      $errors['date']      = 'Date is required.';
        if ($data['time'] === '')      $errors['time']      = 'Time is required.';
        if (!in_array($data['status'], ['pending','confirmed','completed','cancelled'], true)) {
            $errors['status'] = 'Invalid status.';
        }
        if ($errors) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect("/admin/appointments/{$id}/edit");
        }

        try {
            $appt = $this->appointments->load($id);
            if (!$appt) {
                $this->flash('error', 'Appointment not found.');
            } else {
                $slotChanged = (int) $appt->serviceID !== $data['serviceID']
                    || $appt->date !== $data['date']
                    || $appt->time !== $data['time'];
                if ($slotChanged && !$this->appointments->isAvailable($data['date'], $data['time'], $id)) {
                    $this->flash('error', 'That time slot is taken — pick another time.');
                } else {
                    $appt->serviceID = $data['serviceID'];
                    $appt->date      = $data['date'];
                    $appt->time      = $data['time'];
                    $appt->notes     = $data['notes'];
                    $appt->status    = $data['status'];
                    $this->appointments->save($appt);
                    $this->flash('success', 'Appointment updated.');
                }
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not update appointment.');
        }
        return $this->redirect('/admin/appointments');
    }

    public function cancelAppointment(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        try {
            $appt = $this->appointments->load((int) $args['id']);
            if ($appt) {
                $this->appointments->cancel($appt);
                $this->flash('success', 'Appointment cancelled.');
            } else {
                $this->flash('error', 'Appointment not found.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not cancel appointment.');
        }
        return $this->redirect('/admin/appointments');
    }

    // ── Reviews: reply ───────────────────────────────────────────────────
    public function replyToReview(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;

        $reply = (string) (((array) $r->getParsedBody())['reply'] ?? '');
        try {
            $review = $this->reviews->load((int) $args['id']);
            if (!$review) {
                $this->flash('error', 'Review not found.');
            } else {
                $this->reviews->reply($review, $reply);
                $this->flash('success', trim($reply) === '' ? 'Reply removed.' : 'Reply saved.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not save reply.');
        }
        return $this->redirect('/admin/reviews');
    }

    // ── FAQ CRUD ─────────────────────────────────────────────────────────
    public function newFaq(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;
        $form   = $_SESSION['admin_form']   ?? ['sortOrder' => 0];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);
        return $this->render($response, 'admin/faq-form.twig', [
            'mode' => 'create', 'faq' => $form, 'errors' => $errors,
        ]);
    }

    public function createFaq(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;
        $data = $this->extractFaqForm($r);
        if ($errors = $this->validateFaq($data)) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect('/admin/faq/new');
        }
        try {
            $this->faqs->create($data);
            $this->flash('success', 'FAQ entry added.');
        } catch (Throwable $e) {
            $this->flash('error', 'Could not save FAQ.');
        }
        return $this->redirect('/admin/faq');
    }

    public function editFaq(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        $id  = (int) $args['id'];
        $faq = $this->faqs->load($id);
        if (!$faq) {
            $this->flash('error', 'FAQ not found.');
            return $this->redirect('/admin/faq');
        }
        $form = $_SESSION['admin_form'] ?? [
            'category'  => $faq->category,
            'question'  => $faq->question,
            'answer'    => $faq->answer,
            'sortOrder' => $faq->sortOrder,
        ];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);
        return $this->render($response, 'admin/faq-form.twig', [
            'mode' => 'edit', 'id' => $id, 'faq' => $form, 'errors' => $errors,
        ]);
    }

    public function updateFaq(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        $id   = (int) $args['id'];
        $data = $this->extractFaqForm($r);
        if ($errors = $this->validateFaq($data)) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect("/admin/faq/{$id}/edit");
        }
        try {
            $faq = $this->faqs->load($id);
            if (!$faq) { $this->flash('error', 'FAQ not found.'); }
            else {
                $faq->category  = $data['category'];
                $faq->question  = $data['question'];
                $faq->answer    = $data['answer'];
                $faq->sortOrder = (int) $data['sortOrder'];
                $this->faqs->save($faq);
                $this->flash('success', 'FAQ updated.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not update FAQ.');
        }
        return $this->redirect('/admin/faq');
    }

    public function deleteFaq(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        try {
            $faq = $this->faqs->load((int) $args['id']);
            if ($faq) {
                $this->faqs->delete($faq);
                $this->flash('success', 'FAQ deleted.');
            } else {
                $this->flash('error', 'FAQ not found.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not delete FAQ.');
        }
        return $this->redirect('/admin/faq');
    }

    // ── ABOUT CRUD ───────────────────────────────────────────────────────
    public function newAbout(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;
        $form   = $_SESSION['admin_form']   ?? ['sortOrder' => 0];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);
        return $this->render($response, 'admin/about-form.twig', [
            'mode' => 'create', 'section' => $form, 'errors' => $errors,
        ]);
    }

    public function createAbout(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;
        $data = $this->extractAboutForm($r);
        if ($errors = $this->validateAbout($data)) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect('/admin/about/new');
        }
        try {
            $this->about->create($data);
            $this->flash('success', 'Section added.');
        } catch (Throwable $e) {
            $this->flash('error', 'Could not save section.');
        }
        return $this->redirect('/admin/about');
    }

    public function editAbout(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        $id = (int) $args['id'];
        $sec = $this->about->load($id);
        if (!$sec) {
            $this->flash('error', 'Section not found.');
            return $this->redirect('/admin/about');
        }
        $form = $_SESSION['admin_form'] ?? [
            'heading'   => $sec->heading,
            'body'      => $sec->body,
            'sortOrder' => $sec->sortOrder,
        ];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);
        return $this->render($response, 'admin/about-form.twig', [
            'mode' => 'edit', 'id' => $id, 'section' => $form, 'errors' => $errors,
        ]);
    }

    public function updateAbout(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        $id   = (int) $args['id'];
        $data = $this->extractAboutForm($r);
        if ($errors = $this->validateAbout($data)) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect("/admin/about/{$id}/edit");
        }
        try {
            $sec = $this->about->load($id);
            if (!$sec) { $this->flash('error', 'Section not found.'); }
            else {
                $sec->heading   = $data['heading'];
                $sec->body      = $data['body'];
                $sec->sortOrder = (int) $data['sortOrder'];
                $this->about->save($sec);
                $this->flash('success', 'Section updated.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not update section.');
        }
        return $this->redirect('/admin/about');
    }

    public function deleteAbout(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        try {
            $sec = $this->about->load((int) $args['id']);
            if ($sec) {
                $this->about->delete($sec);
                $this->flash('success', 'Section deleted.');
            } else {
                $this->flash('error', 'Section not found.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not delete section.');
        }
        return $this->redirect('/admin/about');
    }

    // ── SERVICE CATEGORY CRUD ────────────────────────────────────────────
    public function newServiceCategory(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;
        $form   = $_SESSION['admin_form']   ?? ['sortOrder' => 0];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);
        return $this->render($response, 'admin/category-form.twig', [
            'mode' => 'create', 'kind' => 'service', 'category' => $form, 'errors' => $errors,
        ]);
    }

    public function createServiceCategory(Request $r, Response $response): Response
    {
        if ($block = $this->guard()) return $block;
        $data = $this->extractCategoryForm($r);
        if ($errors = $this->validateCategory($data)) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect('/admin/service-categories/new');
        }
        try {
            if ($this->serviceCats->findByName($data['name'])) {
                $this->flash('error', 'A category with that name already exists.');
            } else {
                $this->serviceCats->create($data);
                $this->flash('success', 'Category added.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not save category.');
        }
        return $this->redirect('/admin/service-categories');
    }

    public function editServiceCategory(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        $id  = (int) $args['id'];
        $cat = $this->serviceCats->load($id);
        if (!$cat) {
            $this->flash('error', 'Category not found.');
            return $this->redirect('/admin/service-categories');
        }
        $form = $_SESSION['admin_form'] ?? ['name' => $cat->name, 'sortOrder' => $cat->sortOrder];
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_form'], $_SESSION['admin_errors']);
        return $this->render($response, 'admin/category-form.twig', [
            'mode' => 'edit', 'kind' => 'service', 'id' => $id, 'category' => $form, 'errors' => $errors,
        ]);
    }

    public function updateServiceCategory(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        $id   = (int) $args['id'];
        $data = $this->extractCategoryForm($r);
        if ($errors = $this->validateCategory($data)) {
            $_SESSION['admin_errors'] = $errors;
            $_SESSION['admin_form']   = $data;
            return $this->redirect("/admin/service-categories/{$id}/edit");
        }
        try {
            $cat = $this->serviceCats->load($id);
            if (!$cat) {
                $this->flash('error', 'Category not found.');
            } else {
                $cat->name      = $data['name'];
                $cat->sortOrder = (int) $data['sortOrder'];
                $this->serviceCats->save($cat);
                $this->flash('success', 'Category updated.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Could not update category — name might already be in use.');
        }
        return $this->redirect('/admin/service-categories');
    }

    public function deleteServiceCategory(Request $r, Response $response, array $args): Response
    {
        if ($block = $this->guard()) return $block;
        try {
            $cat = $this->serviceCats->load((int) $args['id']);
            if ($cat) {
                $this->serviceCats->delete($cat);
                $this->flash('success', 'Category deleted.');
            } else {
                $this->flash('error', 'Category not found.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Cannot delete: this category is used by existing services.');
        }
        return $this->redirect('/admin/service-categories');
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    private function guard(): ?Response
    {
        if (empty($_SESSION['user'])) {
            return $this->redirect('/login');
        }
        if (($_SESSION['user']['role'] ?? null) !== UserModel::ROLE_ADMIN) {
            $response = new \Slim\Psr7\Response();
            $html = $this->twig->render('Errors/generic.twig', [
                'code' => 403, 'title' => 'Access denied',
                'message' => 'You must be signed in as an admin to view this page.',
            ]);
            $response->getBody()->write($html);
            return $response->withStatus(403)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        return null;
    }

    private function extractServiceForm(Request $r): array
    {
        $body = (array) $r->getParsedBody();
        return [
            'name'        => trim((string) ($body['name']        ?? '')),
            'categoryId'  => (int) ($body['categoryId'] ?? 0),
            'description' => trim((string) ($body['description'] ?? '')),
            'price'       => trim((string) ($body['price']       ?? '')),
            'duration'    => trim((string) ($body['duration']    ?? '')),
        ];
    }

    private function extractFaqForm(Request $r): array
    {
        $body = (array) $r->getParsedBody();
        return [
            'category'  => trim((string) ($body['category'] ?? 'General')),
            'question'  => trim((string) ($body['question'] ?? '')),
            'answer'    => trim((string) ($body['answer']   ?? '')),
            'sortOrder' => (int) ($body['sortOrder'] ?? 0),
        ];
    }

    private function validateFaq(array $data): array
    {
        $errors = [];
        if ($data['category'] === '') $errors['category'] = 'Category is required.';
        if ($data['question'] === '') $errors['question'] = 'Question is required.';
        if ($data['answer']   === '') $errors['answer']   = 'Answer is required.';
        return $errors;
    }

    private function extractCategoryForm(Request $r): array
    {
        $body = (array) $r->getParsedBody();
        return [
            'name'      => trim((string) ($body['name'] ?? '')),
            'sortOrder' => (int) ($body['sortOrder'] ?? 0),
        ];
    }

    private function validateCategory(array $data): array
    {
        $errors = [];
        if ($data['name'] === '') $errors['name'] = 'Name is required.';
        if (strlen($data['name']) > 50) $errors['name'] = 'Name must be 50 characters or fewer.';
        return $errors;
    }

    private function extractAboutForm(Request $r): array
    {
        $body = (array) $r->getParsedBody();
        return [
            'heading'   => trim((string) ($body['heading'] ?? '')),
            'body'      => trim((string) ($body['body']    ?? '')),
            'sortOrder' => (int) ($body['sortOrder'] ?? 0),
        ];
    }

    private function validateAbout(array $data): array
    {
        $errors = [];
        if ($data['heading'] === '') $errors['heading'] = 'Heading is required.';
        if ($data['body']    === '') $errors['body']    = 'Body is required.';
        return $errors;
    }

    private function validateService(array $data): array
    {
        $errors = [];
        if ($data['name']        === '') $errors['name']        = 'Name is required.';
        if (($data['categoryId'] ?? 0) <= 0) $errors['categoryId'] = 'Please pick a category.';
        if ($data['description'] === '') $errors['description'] = 'Description is required.';
        if ($data['price'] === '' || !is_numeric($data['price']) || (float) $data['price'] < 0) {
            $errors['price'] = 'Price must be a non-negative number.';
        }
        if ($data['duration'] === '' || !ctype_digit((string) $data['duration']) || (int) $data['duration'] <= 0) {
            $errors['duration'] = 'Duration must be a positive whole number of minutes.';
        }
        return $errors;
    }

    /**
     * Build display-ready appointment rows by joining beans with their
     * service + user beans in PHP. Avoids leaking SQL into the controllers.
     */
    private function loadAppointmentRows(): array
    {
        $rows = [];
        foreach ($this->appointments->findAll() as $a) {
            $svc  = R::load('services', (int) $a->serviceID);
            $user = $a->userID ? R::load('user', (int) $a->userID) : null;

            $rows[] = [
                'id'            => (int) $a->id,
                'serviceID'     => (int) $a->serviceID,
                'serviceName'   => $svc->id ? $svc->name : '—',
                'date'          => $a->date,
                'time'          => $a->time,
                'status'        => $a->status,
                'notes'         => $a->notes,
                'customerName'  => $user && $user->id
                    ? trim($user->firstName . ' ' . $user->lastName)
                    : ($a->guestName ?: 'Guest'),
                'customerEmail' => $user && $user->id ? $user->email       : $a->guestEmail,
                'customerPhone' => $user && $user->id ? $user->phoneNumber : $a->guestPhone,
                'customerType'  => $a->userID ? 'client' : 'guest',
            ];
        }
        return $rows;
    }

    private function loadServiceRows(): array
    {
        // ServiceModel already joins the category name in its helper.
        return $this->services->findAllWithCategory();
    }

    private function loadFaqRows(): array
    {
        $rows = [];
        foreach ($this->faqs->findAll() as $f) {
            $rows[] = [
                'id'         => (int) $f->id,
                'category'   => $f->category,
                'question'   => $f->question,
                'answer'     => $f->answer,
                'sortOrder'  => (int) $f->sortOrder,
            ];
        }
        return $rows;
    }

    private function loadReviewRows(): array
    {
        $rows = [];
        foreach ($this->reviews->findAll() as $rv) {
            $author = R::load('user', (int) $rv->userID);
            $appt   = R::load('appointment', (int) $rv->appointmentID);
            $svc    = $appt->id ? R::load('services', (int) $appt->serviceID) : null;
            $rows[] = [
                'id'            => (int) $rv->id,
                'authorName'    => $author->id ? trim($author->firstName . ' ' . $author->lastName) : 'Anonymous',
                'serviceName'   => $svc && $svc->id ? $svc->name : '—',
                'rating'        => (int) $rv->rating,
                'comment'       => $rv->comment,
                'reviewDate'    => $rv->reviewDate,
                'reply'         => $rv->reply,
                'repliedAt'     => $rv->repliedAt,
            ];
        }
        return $rows;
    }

    private function loadPaymentRows(): array
    {
        $rows = [];
        foreach ($this->payments->findAll() as $p) {
            $appt = R::load('appointment', (int) $p->appointmentID);
            $rows[] = [
                'id'              => (int) $p->id,
                'paymentFrom'     => $p->paymentFrom,
                'payerName'       => $p->paymentFromName,
                'payerEmail'      => $p->paymentFromEmail,
                'payerPhone'      => $p->paymentFromPhone,
                'paymentType'     => $p->paymentType,
                'paymentAmount'   => $p->paymentAmount,
                'paymentStatus'   => $p->paymentStatus,
                'created_at'      => $p->created_at,
                'appointmentDate' => $appt->id ? $appt->date : null,
                'appointmentTime' => $appt->id ? $appt->time : null,
                'payerType'       => $p->paymentFrom ? 'client' : 'guest',
            ];
        }
        return $rows;
    }

    private function buildInsights(): array
    {
        $totalRevenue   = 0.0;
        $revenuePaid    = 0.0;
        $revenuePending = 0.0;
        $count          = 0;

        foreach ($this->payments->findAll() as $p) {
            $amount = (float) $p->paymentAmount;
            $count++;
            $totalRevenue += $amount;
            if ($p->paymentStatus === 'paid')    $revenuePaid    += $amount;
            if ($p->paymentStatus === 'pending') $revenuePending += $amount;
        }

        return [
            'totals' => [
                'total_count'     => $count,
                'total_revenue'   => $totalRevenue,
                'revenue_paid'    => $revenuePaid,
                'revenue_pending' => $revenuePending,
                'average_amount'  => $count > 0 ? $totalRevenue / $count : 0.0,
            ],
        ];
    }
}
