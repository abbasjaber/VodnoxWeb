<?php

use App\Http\Controllers\Admin\AuditController as AdminAudit;
use App\Http\Controllers\Admin\BillingController as AdminBilling;
use App\Http\Controllers\Admin\DashboardController as AdminDashboard;
use App\Http\Controllers\Admin\FeatureSubscriptionController as AdminFeatureSubscription;
use App\Http\Controllers\Admin\GatewayController as AdminGateway;
use App\Http\Controllers\Admin\IntegrationController as AdminIntegration;
use App\Http\Controllers\Admin\OrganizationController as AdminOrganization;
use App\Http\Controllers\Admin\PlanController as AdminPlan;
use App\Http\Controllers\Admin\ServerController as AdminServer;
use App\Http\Controllers\Admin\SettingsController as AdminSettings;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscription;
use App\Http\Controllers\Admin\TeamController as AdminTeam;
use App\Http\Controllers\Admin\UsageController as AdminUsage;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Company\AuditController as CompanyAudit;
use App\Http\Controllers\Company\CallController as CompanyCall;
use App\Http\Controllers\Company\CommunicationsController as CompanyCommunications;
use App\Http\Controllers\Company\CrmController as CompanyCrm;
use App\Http\Controllers\Company\CustomerController as CompanyCustomer;
use App\Http\Controllers\Company\DashboardController as CompanyDashboard;
use App\Http\Controllers\Company\DepartmentController as CompanyDepartment;
use App\Http\Controllers\Company\ExtensionController as CompanyExtension;
use App\Http\Controllers\Company\FormController as CompanyForm;
use App\Http\Controllers\Company\FormSubmissionController as CompanyFormSubmission;
use App\Http\Controllers\Company\GatewayController as CompanyGateway;
use App\Http\Controllers\Company\IvrController as CompanyIvr;
use App\Http\Controllers\Company\NumberController as CompanyNumber;
use App\Http\Controllers\Company\OpportunityController as CompanyOpportunity;
use App\Http\Controllers\Company\QueueController as CompanyQueue;
use App\Http\Controllers\Company\RecordingController as CompanyRecording;
use App\Http\Controllers\Company\ReportController as CompanyReport;
use App\Http\Controllers\Company\SettingsController as CompanySettings;
use App\Http\Controllers\Company\SmartIvrController as CompanySmartIvr;
use App\Http\Controllers\Company\SubscriptionController as CompanySubscription;
use App\Http\Controllers\Company\TaskController as CompanyTask;
use App\Http\Controllers\Company\TeamController as CompanyTeam;
use App\Http\Controllers\Company\ComplaintController as CompanyComplaint;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Public\ContactController;
use App\Http\Controllers\Public\FormController as PublicForm;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\User\AccountController as UserAccount;
use App\Http\Controllers\User\ContactController as UserContact;
use App\Http\Controllers\User\DashboardController as UserDashboard;
use App\Http\Controllers\User\HistoryController as UserHistory;
use App\Http\Controllers\User\IncomingController as UserIncoming;
use App\Http\Controllers\User\ReceptionController as UserReception;
use App\Http\Controllers\User\TelephonyController as UserTelephony;
use App\Http\Controllers\User\VoicemailController as UserVoicemail;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Website
|--------------------------------------------------------------------------
*/

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/services', [PageController::class, 'services'])->name('services');
Route::get('/pricing', [PageController::class, 'pricing'])->name('pricing');
Route::get('/about', [PageController::class, 'about'])->name('about');
Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('contact.store');

// Public form pages (opaque token).
Route::get('/f/{token}', [PublicForm::class, 'show'])->name('forms.public.show');
Route::post('/f/{token}', [PublicForm::class, 'submit'])
    ->middleware('throttle:20,1')
    ->name('forms.public.submit');

Route::get('/locale/{lang}', [LocaleController::class, 'switch'])->name('locale.switch');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});

/*
|--------------------------------------------------------------------------
| Super Admin
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboard::class, 'index'])->name('index');

    // Organizations (accounts)
    Route::get('organizations', [AdminOrganization::class, 'index'])->name('organizations.index');
    Route::post('organizations', [AdminOrganization::class, 'store'])->name('organizations.store');
    Route::get('organizations/{organization}', [AdminOrganization::class, 'show'])->name('organizations.show');
    Route::put('organizations/{organization}', [AdminOrganization::class, 'update'])->name('organizations.update');
    Route::patch('organizations/{organization}/status', [AdminOrganization::class, 'updateStatus'])->name('organizations.status');
    Route::delete('organizations/{organization}', [AdminOrganization::class, 'destroy'])->name('organizations.destroy');

    // Subscriptions
    Route::get('subscriptions', [AdminSubscription::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions', [AdminSubscription::class, 'store'])->name('subscriptions.store');
    Route::put('subscriptions/{subscription}', [AdminSubscription::class, 'update'])->name('subscriptions.update');
    Route::delete('subscriptions/{subscription}', [AdminSubscription::class, 'destroy'])->name('subscriptions.destroy');

    // Plans
    Route::get('plans', [AdminPlan::class, 'index'])->name('plans.index');
    Route::post('plans', [AdminPlan::class, 'store'])->name('plans.store');
    Route::put('plans/{plan}', [AdminPlan::class, 'update'])->name('plans.update');
    Route::delete('plans/{plan}', [AdminPlan::class, 'destroy'])->name('plans.destroy');

    // Billing (invoices + payments)
    Route::get('billing', [AdminBilling::class, 'index'])->name('billing.index');
    Route::post('billing/invoices', [AdminBilling::class, 'storeInvoice'])->name('billing.invoices.store');
    Route::put('billing/invoices/{invoice}', [AdminBilling::class, 'updateInvoice'])->name('billing.invoices.update');
    Route::post('billing/invoices/{invoice}/payments', [AdminBilling::class, 'storePayment'])->name('billing.payments.store');
    Route::patch('billing/payments/{payment}/confirm', [AdminBilling::class, 'confirmPayment'])->name('billing.payments.confirm');
    Route::patch('billing/payments/{payment}/reject', [AdminBilling::class, 'rejectPayment'])->name('billing.payments.reject');

    // Usage
    Route::get('usage', [AdminUsage::class, 'index'])->name('usage.index');

    // Telephony servers
    Route::get('servers', [AdminServer::class, 'index'])->name('servers.index');
    Route::post('servers', [AdminServer::class, 'store'])->name('servers.store');
    Route::put('servers/{server}', [AdminServer::class, 'update'])->name('servers.update');
    Route::delete('servers/{server}', [AdminServer::class, 'destroy'])->name('servers.destroy');
    Route::post('servers/{server}/test', [AdminServer::class, 'test'])->name('servers.test');

    // GSM gateways (read-only cross-organization surveillance)
    Route::get('gateways', [AdminGateway::class, 'index'])->name('gateways.index');
    Route::post('gateways/{gateway}/refresh', [AdminGateway::class, 'refresh'])->name('gateways.refresh');

    // Integrations
    Route::get('integrations', [AdminIntegration::class, 'index'])->name('integrations.index');
    Route::post('integrations', [AdminIntegration::class, 'store'])->name('integrations.store');
    Route::put('integrations/{integration}', [AdminIntegration::class, 'update'])->name('integrations.update');
    Route::delete('integrations/{integration}', [AdminIntegration::class, 'destroy'])->name('integrations.destroy');

    // Platform team
    Route::get('team', [AdminTeam::class, 'index'])->name('team.index');
    Route::post('team', [AdminTeam::class, 'store'])->name('team.store');
    Route::put('team/{user}', [AdminTeam::class, 'update'])->name('team.update');
    Route::delete('team/{user}', [AdminTeam::class, 'destroy'])->name('team.destroy');

    // Audit log
    Route::get('audit', [AdminAudit::class, 'index'])->name('audit.index');

    // Platform settings
    Route::get('settings', [AdminSettings::class, 'index'])->name('settings.index');
    Route::put('settings', [AdminSettings::class, 'update'])->name('settings.update');

    // Feature subscription management (CRM / FORMS / Smart IVR)
    Route::get('crm', [AdminFeatureSubscription::class, 'index'])->name('crm.index');
    Route::get('forms', [AdminFeatureSubscription::class, 'index'])->name('forms.index');
    Route::get('smart-ivr', [AdminFeatureSubscription::class, 'index'])->name('smart-ivr.index');
    Route::post('features/{featureCode}', [AdminFeatureSubscription::class, 'store'])->name('features.store');
    Route::put('features/{featureSubscription}', [AdminFeatureSubscription::class, 'update'])->name('features.update');
    Route::delete('features/{featureSubscription}', [AdminFeatureSubscription::class, 'destroy'])->name('features.destroy');
    Route::patch('activation-requests/{activationRequest}', [AdminFeatureSubscription::class, 'decide'])->name('activation-requests.decide');
});

/*
|--------------------------------------------------------------------------
| Organization (Company) Workspace
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant', 'role:organization_admin,supervisor'])->prefix('company')->name('company.')->group(function () {
    Route::get('/', [CompanyDashboard::class, 'index'])->name('index');

    // Team
    Route::get('team', [CompanyTeam::class, 'index'])->name('team.index');
    Route::post('team', [CompanyTeam::class, 'store'])->name('team.store');
    Route::put('team/{member}', [CompanyTeam::class, 'update'])->name('team.update');
    Route::delete('team/{member}', [CompanyTeam::class, 'destroy'])->name('team.destroy');

    // Departments
    Route::get('departments', [CompanyDepartment::class, 'index'])->name('departments.index');
    Route::post('departments', [CompanyDepartment::class, 'store'])->name('departments.store');
    Route::put('departments/{department}', [CompanyDepartment::class, 'update'])->name('departments.update');
    Route::delete('departments/{department}', [CompanyDepartment::class, 'destroy'])->name('departments.destroy');

    // Extensions & devices
    Route::get('extensions', [CompanyExtension::class, 'index'])->name('extensions.index');
    Route::post('extensions', [CompanyExtension::class, 'store'])->name('extensions.store');
    Route::put('extensions/{extension}', [CompanyExtension::class, 'update'])->name('extensions.update');
    Route::delete('extensions/{extension}', [CompanyExtension::class, 'destroy'])->name('extensions.destroy');
    Route::post('extensions/{extension}/retry', [CompanyExtension::class, 'retry'])->name('extensions.retry');

    // Call queues
    Route::get('queues', [CompanyQueue::class, 'index'])->name('queues.index');
    Route::post('queues', [CompanyQueue::class, 'store'])->name('queues.store');
    Route::put('queues/{queue}', [CompanyQueue::class, 'update'])->name('queues.update');
    Route::delete('queues/{queue}', [CompanyQueue::class, 'destroy'])->name('queues.destroy');

    // IVR menus
    Route::get('ivr', [CompanyIvr::class, 'index'])->name('ivr.index');
    Route::post('ivr', [CompanyIvr::class, 'store'])->name('ivr.store');
    Route::put('ivr/{ivr}', [CompanyIvr::class, 'update'])->name('ivr.update');
    Route::delete('ivr/{ivr}', [CompanyIvr::class, 'destroy'])->name('ivr.destroy');

    // Phone numbers & routing
    Route::get('numbers', [CompanyNumber::class, 'index'])->name('numbers.index');
    Route::post('numbers', [CompanyNumber::class, 'store'])->name('numbers.store');
    Route::put('numbers/{number}', [CompanyNumber::class, 'update'])->name('numbers.update');
    Route::delete('numbers/{number}', [CompanyNumber::class, 'destroy'])->name('numbers.destroy');

    // Call history
    Route::get('calls', [CompanyCall::class, 'index'])->name('calls.index');
    Route::get('calls/{call}', [CompanyCall::class, 'show'])->name('calls.show');

    // Recordings
    Route::get('recordings', [CompanyRecording::class, 'index'])->name('recordings.index');

    // Performance reports
    Route::get('reports', [CompanyReport::class, 'index'])->name('reports.index');

    // Organization subscription & billing
    Route::get('subscription', [CompanySubscription::class, 'index'])->name('subscription.index');
    Route::post('subscription/request', [CompanySubscription::class, 'requestActivation'])->name('subscription.request');

    // Organization settings
    Route::get('settings', [CompanySettings::class, 'index'])->name('settings.index');
    Route::put('settings', [CompanySettings::class, 'update'])->name('settings.update');

    // Activity log
    Route::get('audit', [CompanyAudit::class, 'index'])->name('audit.index');

    // Communications settings (auto-available, not an add-on)
    Route::get('communications', [CompanyCommunications::class, 'index'])->name('communications.index');
    Route::post('communications', [CompanyCommunications::class, 'store'])->name('communications.store');
    Route::put('communications/{schedule}', [CompanyCommunications::class, 'update'])->name('communications.update');
    Route::delete('communications/{schedule}', [CompanyCommunications::class, 'destroy'])->name('communications.destroy');
    Route::post('communications/{schedule}/test', [CompanyCommunications::class, 'test'])->name('communications.test');

    /*
    | GSM gateways (GoIP lines).
    | Visible to the organization administrator and the supervisor, but each
    | action is gated by its own permission *inside* the controller: a
    | supervisor may look, never manage, and can never read a SIP secret just
    | by being inside this route group.
    */
    Route::get('gateways', [CompanyGateway::class, 'index'])->name('gateways.index');
    Route::post('gateways', [CompanyGateway::class, 'store'])->name('gateways.store');
    Route::put('gateways/{gateway}', [CompanyGateway::class, 'update'])->name('gateways.update');
    Route::delete('gateways/{gateway}', [CompanyGateway::class, 'destroy'])->name('gateways.destroy');
    Route::post('gateways/{gateway}/retry', [CompanyGateway::class, 'retry'])->name('gateways.retry');
    Route::post('gateways/{gateway}/refresh', [CompanyGateway::class, 'refresh'])->name('gateways.refresh');
    Route::post('gateways/{gateway}/toggle', [CompanyGateway::class, 'toggle'])->name('gateways.toggle');
    Route::put('gateways/{gateway}/lines/{line}', [CompanyGateway::class, 'updateLine'])->name('gateways.lines.update');
    Route::post('gateways/{gateway}/lines/{line}/rotate', [CompanyGateway::class, 'rotate'])->name('gateways.lines.rotate');
    Route::get('gateways/{gateway}/lines/{line}/secret', [CompanyGateway::class, 'secret'])->name('gateways.lines.secret');

    /*
    | CRM (independent subscription)
    | The landing page renders an activation-request page when the feature
    | is not active; every data route below is hard-gated server-side.
    */
    Route::get('crm', [CompanyCrm::class, 'index'])->name('crm.index');

    Route::middleware('feature:crm')->group(function () {
        Route::get('crm/customers', [CompanyCustomer::class, 'index'])->name('crm.customers.index');
        Route::post('crm/customers', [CompanyCustomer::class, 'store'])->name('crm.customers.store');
        Route::get('crm/customers/{customer}', [CompanyCustomer::class, 'show'])->name('crm.customers.show');
        Route::put('crm/customers/{customer}', [CompanyCustomer::class, 'update'])->name('crm.customers.update');
        Route::delete('crm/customers/{customer}', [CompanyCustomer::class, 'destroy'])->name('crm.customers.destroy');
        Route::post('crm/customers/{customer}/notes', [CompanyCustomer::class, 'storeNote'])->name('crm.customers.notes.store');
        Route::post('crm/customers/{customer}/interactions', [CompanyCustomer::class, 'storeInteraction'])->name('crm.customers.interactions.store');

        Route::get('crm/opportunities', [CompanyOpportunity::class, 'index'])->name('crm.opportunities.index');
        Route::post('crm/opportunities', [CompanyOpportunity::class, 'store'])->name('crm.opportunities.store');
        Route::put('crm/opportunities/{opportunity}', [CompanyOpportunity::class, 'update'])->name('crm.opportunities.update');
        Route::delete('crm/opportunities/{opportunity}', [CompanyOpportunity::class, 'destroy'])->name('crm.opportunities.destroy');

        Route::get('crm/tasks', [CompanyTask::class, 'index'])->name('crm.tasks.index');
        Route::post('crm/tasks', [CompanyTask::class, 'store'])->name('crm.tasks.store');
        Route::put('crm/tasks/{task}', [CompanyTask::class, 'update'])->name('crm.tasks.update');
        Route::delete('crm/tasks/{task}', [CompanyTask::class, 'destroy'])->name('crm.tasks.destroy');

        Route::get('crm/complaints', [CompanyComplaint::class, 'index'])->name('crm.complaints.index');
        Route::post('crm/complaints', [CompanyComplaint::class, 'store'])->name('crm.complaints.store');
        Route::put('crm/complaints/{complaint}', [CompanyComplaint::class, 'update'])->name('crm.complaints.update');

        Route::get('crm/reports', [CompanyCrm::class, 'reports'])->name('crm.reports.index');
    });

    /*
    | VODNOX FORMS (independent subscription)
    | The landing page renders an activation-request page when inactive.
    */
    Route::get('forms', [CompanyForm::class, 'index'])->name('forms.index');

    Route::middleware('feature:forms')->group(function () {
        Route::post('forms', [CompanyForm::class, 'store'])->name('forms.store');
        Route::get('forms/{form}/builder', [CompanyForm::class, 'builder'])->name('forms.builder');
        Route::put('forms/{form}', [CompanyForm::class, 'update'])->name('forms.update');
        Route::delete('forms/{form}', [CompanyForm::class, 'destroy'])->name('forms.destroy');
        Route::post('forms/{form}/fields', [CompanyForm::class, 'storeField'])->name('forms.fields.store');
        Route::delete('forms/{form}/fields/{field}', [CompanyForm::class, 'destroyField'])->name('forms.fields.destroy');
        Route::post('forms/{form}/publish', [CompanyForm::class, 'publish'])->name('forms.publish');

        Route::get('forms/{form}/submissions', [CompanyFormSubmission::class, 'index'])->name('forms.submissions.index');
        Route::get('forms/{form}/submissions/export', [CompanyFormSubmission::class, 'export'])->name('forms.submissions.export');
        Route::get('forms/{form}/submissions/{submission}', [CompanyFormSubmission::class, 'show'])->name('forms.submissions.show');
    });

    /*
    | Smart IVR (independent subscription, "coming soon")
    */
    Route::get('smart-ivr', [CompanySmartIvr::class, 'index'])->name('smart-ivr.index');
});

/*
|--------------------------------------------------------------------------
| Agent (User) Workspace
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant'])->prefix('user')->name('user.')->group(function () {
    Route::get('/', [UserDashboard::class, 'index'])->name('index');

    Route::get('contacts', [UserContact::class, 'index'])->name('contacts.index');
    Route::post('contacts', [UserContact::class, 'store'])->name('contacts.store');
    Route::delete('contacts/{favorite}', [UserContact::class, 'destroy'])->name('contacts.destroy');

    Route::get('history', [UserHistory::class, 'index'])->name('history.index');
    Route::get('voicemail', [UserVoicemail::class, 'index'])->name('voicemail.index');
    Route::get('tasks', [UserHistory::class, 'tasks'])->name('tasks.index');

    Route::get('settings', [UserAccount::class, 'index'])->name('settings.index');
    Route::put('settings', [UserAccount::class, 'update'])->name('settings.update');

    Route::get('incoming', [UserIncoming::class, 'index'])->name('incoming.index');
    Route::post('incoming', [UserIncoming::class, 'store'])->name('incoming.store');

    Route::get('reception', [UserReception::class, 'index'])->name('reception.index');
    Route::post('reception', [UserReception::class, 'store'])->name('reception.store');

    /*
    | Telephony (browser phone). Credentials are per-user, uncached and gated
    | by the employee's own account/extension state.
    */
    Route::get('telephony/credentials', [UserTelephony::class, 'credentials'])->name('telephony.credentials');
    Route::post('telephony/provision', [UserTelephony::class, 'provision'])
        ->middleware('throttle:6,1')
        ->name('telephony.provision');
    Route::post('telephony/registration', [UserTelephony::class, 'registration'])
        ->middleware('throttle:30,1')
        ->name('telephony.registration');
});
