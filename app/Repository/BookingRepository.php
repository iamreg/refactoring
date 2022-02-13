<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->handleLogging();
    }

    /*
     * Handle logging
     */
    private function handleLogging()
    {
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $userId
     * @return array
     */
    public function getUsersJobs($userId)
    {
        $user = User::find($userId);
        $emergencyJobs = array();
        $normalJobs = array();
        $userType = '';

        switch (true) {
            case $user && $user->is('customer'):
                $jobs = $this->getCustomerJobs($user);
                $userType = 'customer';
                break;
            case $user && $user->is('translator'):
                $jobs = Job::getTranslatorJobs($user->id, 'new');
                $jobs = $jobs->pluck('jobs')->all();
                $userType = 'translator';
                break;
        }


        if ($jobs) {
            foreach ($jobs as $job) {
                if ($job->immediate === 'yes') {
                    $emergencyJobs[] = $job;
                } else {
                    $normalJobs[] = $job;
                }
            }

            if (count($normalJobs) > 0) {
                $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($userId) {
                    $item['usercheck'] = Job::checkParticularJob($userId, $item);
                })->sortBy('due')->all();
            }
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'user' => $user,
            'usertype' => $userType
        ];
    }

    /**
     * @param User $user
     * @return array
     */
    protected function getCustomerJobs(User $user)
    {
        return $user->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
            ->whereIn('status', ['pending', 'assigned', 'started'])
            ->orderBy('due', 'asc')
            ->get();
    }

    /**
     * @param $userId
     * @return array
     */
    public function getUsersJobsHistory($userId, Request $request)
    {
        $user = User::find($userId);

        $jobHistory = [
            'emergencyJobs' => [],
            'normalJobs' => [],
            'jobs' => [],
            'user' => $user,
            'userType' => '',
            'numOfPages' => 0,
            'pageNumber' => 0
        ];

        switch (true) {
            case $user && $user->is('customer'):
                $jobs = $this->getCustomerJobsHistory($user);
                $jobHistory['jobs'] = $jobs;
                $jobHistory['userType'] = 'customer';
                break;
            case $user && $user->is('translator'):
                $page = $request->get('page');
                $pageNumber = isset($page) ? $page : 1;

                $jobsIds = Job::getTranslatorJobsHistoric($user->id, 'historic', $pageNumber);
                $numOfPages = ceil($jobsIds->total() / 15);
                $jobHistory['jobs'] = $jobsIds;
                $jobHistory['normalJobs'] = $jobsIds;
                $jobHistory['numOfPages'] = $numOfPages;
                $jobHistory['pageNumber'] = $pageNumber;
                $jobHistory['userType'] = 'translator';
                break;
        }

        return $jobHistory;
    }

    /**
     * @param User $user
     * @param $perPage
     * @return array
     */
    protected function getCustomerJobsHistory(User $user, $perPage = 15)
    {
        return $user->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
            ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
            ->orderBy('due', 'desc')
            ->paginate($perPage);
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {

        $immediateTime = 5;
        $consumerType = $user->userMeta->consumer_type;

        $response['status'] = 'fail';

        if ($user->user_type === env('CUSTOMER_ROLE_ID')) {

            $response['message'] = "Du måste fylla in alla fält";

            if (!isset($data['from_language_id'])) {
                $response['field_name'] = "from_language_id";
                return $response;
            }

            if ($data['immediate'] === 'no') {
                if (isset($data['due_date']) && $data['due_date'] == '') {
                    $response['field_name'] = "due_date";
                    return $response;
                }
                if (isset($data['due_time']) && $data['due_time'] == '') {
                    $response['field_name'] = "due_time";
                    return $response;
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    $response['message'] = "Du måste göra ett val här";
                    $response['field_name'] = "customer_phone_type";
                    return $response;
                }
            }

            if (isset($data['duration']) && $data['duration'] == '') {
                $response['field_name'] = "duration";
                return $response;
            }

            $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';

            $customerPhysicalType = isset($data['customer_physical_type']) ? 'yes' : 'no';
            $data['customer_physical_type'] = $customerPhysicalType;
            $response['customer_physical_type'] = $customerPhysicalType;


            if ($data['immediate'] == 'yes') {
                $dueDate = Carbon::now()->addMinute($immediateTime);
                $data['due'] = $dueDate->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';

            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $dueDate = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $dueDate->format('Y-m-d H:i:s');

                if ($dueDate->isPast()) {
                    $response['message'] = "Can't create booking for past dates.";
                    return $response;
                }
            }

            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } elseif (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }

            switch (true) {
                case in_array('normal', $data['job_for']):
                    $data['certified'] = 'normal';

                    if (in_array('certified', $data['job_for'])) {
                        $data['certified'] = 'both';
                    }

                    if (in_array('certified_in_law', $data['job_for'])) {
                        $data['certified'] = 'n_law';
                    }

                    if (in_array('certified_in_health', $data['job_for'])) {
                        $data['certified'] = 'n_health';
                    }
                    break;
                case in_array('certified', $data['job_for']):
                    $data['certified'] = 'yes';
                    break;
                case in_array('certified_in_law', $data['job_for']):
                    $data['certified'] = 'law';
                    break;
                case in_array('certified_in_health', $data['job_for']):
                    $data['certified'] = 'health';
            }

            switch ($consumerType) {
                case 'rwsconsumer':
                    $data['job_type'] = 'rws';
                    break;
                case 'ngo':
                    $data['job_type'] = 'ngo';
                    break;
                case 'paid':
                    $data['job_type'] = 'paid';
            }

            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due)) {
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            }

            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

            $job = $user->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
        } else {
            $response['message'] = "Unable to create booking for translator.";
        }

        return $response;
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $userType = $data['user_type'];
        $job = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->get()->first();

        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }

        $job->save();

        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $sendData = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $sendData);

        $response['type'] = $userType;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = $this->jobToData($job);

        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;

    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        // save job's information to data for sending Push
        $data = $this->getJobDataArray($job);
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        if ($job->certified != null) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law':
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $userId
     * @return array
     */
    public function getPotentialJobIdsWithUserId($userId)
    {
        $userMeta = UserMeta::where('user_id', $userId)->first();
        $translatorType = $userMeta->translator_type;

        switch ($translatorType) {
            case 'professional':
                $jobType = 'paid';   /*show all jobs for professionals.*/
                break;
            case 'rwstranslator':
                $jobType = 'rws';  /* for rwstranslator only show rws jobs. */
                break;
            case 'volunteer':
            default:
                $jobType = 'unpaid';  /* for volunteers only show unpaid jobs. */

        }

        $languages = UserLanguages::where('user_id', '=', $userId)->get();
        $userLanguage = collect($languages)->pluck('lang_id')->all();

        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;

        $jobIds = Job::getJobs($userId, $jobType, 'pending', $userLanguage, $gender, $translatorLevel);
        foreach ($jobIds as $k => $v)     // checking translator town
        {
            $job = Job::find($v->id);
            $checkTown = Job::checkTowns($job->user_id, $userId);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checkTown) {
                unset($jobIds[$k]);
            }
        }
        return TeHelper::convertJobIdsInObjs($jobIds);
    }

    /**
     * Function to delay the push
     * @param $userId
     * @return bool
     */
    public function isNeedToDelayPush($userId)
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }

        $notGetNightTime = TeHelper::getUsermeta($userId, 'not_get_nighttime');
        return $notGetNightTime === 'yes';
    }

    /**
     * Function to check if need to send the push
     * @param $userId
     * @return bool
     */
    public function isNeedToSendPush($userId)
    {
        $notGetNotification = TeHelper::getUsermeta($userId, 'not_get_notification');
        return !($notGetNotification === 'yes');

    }

    /*
     * Get job type based on given translator
     * @param $translatorType
     * return string
     */
    private function getJobType($translatorType)
    {
        switch ($translatorType) {
            case 'professional':
                return 'paid';   /*show all jobs for professionals.*/
            case 'rwstranslator':
                return 'rws';  /* for rwstranslator only show rws jobs. */
            case 'volunteer':
            default:
                return 'unpaid';  /* for volunteers only show unpaid jobs. */

        }
    }

    /*
   * Get translator type based on given job
   * @param $jobType
   * return string
   */
    private function getTranslatorType($jobType)
    {
        switch ($jobType) {
            case 'paid':
                return 'professional';
            case 'rws':
                return 'rwstranslator';
            case 'unpaid':
            default:
                return 'volunteer';
        }
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {

        $jobType = $job->job_type;
        $translatorType = $this->getTranslatorType($jobType);


        $jobLanguage = $job->from_language_id;
        $gender = $job->gender;
        $translatorLevel = [];

        if (!empty($job->certified)) {
            switch (true) {
                case $job->certified == 'yes' || $job->certified == 'both':
                    $translatorLevel[] = 'Certified';
                    $translatorLevel[] = 'Certified with specialisation in law';
                    $translatorLevel[] = 'Certified with specialisation in health care';
                    break;
                case $job->certified == 'law' || $job->certified == 'n_law':
                    $translatorLevel[] = 'Certified with specialisation in law';
                    break;
                case $job->certified == 'health' || $job->certified == 'n_health':
                    $translatorLevel[] = 'Certified with specialisation in health care';
                    break;
                case $job->certified == 'normal' || $job->certified == 'both':
                    $translatorLevel[] = 'Layman';
                    $translatorLevel[] = 'Read Translation courses';
            }
        } else {
            $translatorLevel[] = 'Certified';
            $translatorLevel[] = 'Certified with specialisation in law';
            $translatorLevel[] = 'Certified with specialisation in health care';
            $translatorLevel[] = 'Layman';
            $translatorLevel[] = 'Read Translation courses';
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        return User::getPotentialUsers($translatorType, $jobLanguage, $gender, $translatorLevel, $translatorsId);
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $user)
    {
        $job = Job::find($id);

        $currentTranslator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($currentTranslator)) {
            $currentTranslator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();
        }

        $logData = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($currentTranslator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $logData[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $oldTime = $job->due;
            $job->due = $data['due'];
            $logData[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $logData[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $oldLang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $logData[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $user->id . '(' . $user->name . ')' .
            ' has been updated booking <a class="openjob" href="/admin/jobs/' .
            $id . '">#' . $id . '</a> with data:  ', $logData);

        $job->reference = $data['reference'];
        $job->save();

        if ($job->due >= Carbon::now()) {
            if ($changeDue['dateChanged'] && $oldTime) {
                $this->sendChangedDateNotification($job, $oldTime);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $currentTranslator, $changeTranslator['new_translator']);
            }
            if ($langChanged && $oldLang) {
                $this->sendChangedLangNotification($job, $oldLang);
            }
        }
        return ['Updated'];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $oldStatus = $job->status;
        $response = ['statusChanged' => false, 'log_data' => []];

        // Immediately handle failing cases
        if ($oldStatus == $data['status']) {
            return $response;
        }

        switch ($job->status) {
            case 'timedout':
                $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                break;
            case 'completed':
                $statusChanged = $this->changeCompletedStatus($job, $data);
                break;
            case 'started':
                $statusChanged = $this->changeStartedStatus($job, $data);
                break;
            case 'pending':
                $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                break;
            case 'withdrawafter24':
                $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                break;
            case 'assigned':
                $statusChanged = $this->changeAssignedStatus($job, $data);
                break;
            default:
                $statusChanged = false;
        }

        if ($statusChanged) {
            $logData = [
                'old_status' => $oldStatus,
                'new_status' => $data['status']
            ];
            $response['statusChanged'] = true;
            $response['log_data'] = $logData;
        }

        return $response;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        $user = $job->user()->first();

        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $jobData = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            // send Push all suitable translators
            $this->sendNotificationTranslator($job, $jobData, '*');

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {

        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }

        if ($job->save()) {
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] == '' || $data['session_time'] == '') return false;

        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] == 'completed') {
            $user = $job->user()->first();

            $interval = $data['session_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $sessionTime = $diff[0] . ' tim ' . $diff[1] . ' min';

            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;

            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $sessionTime,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)
                ->where('cancel_at', Null)
                ->first();
            $email = $user->user->email;
            $name = $user->user->name;

            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $sessionTime,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        }

        if ($job->save()) {
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        $job->save();

        if ($data['status'] == 'assigned' && $changedTranslator) {
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
        }

        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if ($data['status'] == 'timedout') {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;

            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;

            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
                $email = $user->user->email;
                $name = $user->user->name;

                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $currentTranslator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($currentTranslator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($currentTranslator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $logData = [];
            if (!is_null($currentTranslator) && (isset($data['translator']) && $data['translator'] != 0)) {

                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $newTranslator = $currentTranslator->toArray();
                $newTranslator['user_id'] = $data['translator'];
                unset($newTranslator['id']);
                $newTranslator = Translator::create($newTranslator);
                $currentTranslator->cancel_at = Carbon::now();
                $currentTranslator->save();
                $logData[] = [
                    'old_translator' => $currentTranslator->user->email,
                    'new_translator' => $newTranslator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($currentTranslator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $newTranslator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $logData[] = [
                    'old_translator' => null,
                    'new_translator' => $newTranslator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged) {
                return ['translatorChanged' => true, 'new_translator' => $newTranslator, 'log_data' => $logData];
            }

        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        if ($old_due != $new_due) {
            $logData = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            return ['dateChanged' => true, 'log_data' => $logData];
        }

        return ['dateChanged' => false];
    }

    /*
     * Store $job data to an array
     * @params Job $job
     * return array
     */
    private function getJobDataArray($job)
    {
        // save job's information to data for sending Push
        $data = array();
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;

        $dueDate = explode(" ", $job->due);
        $dueDate = $dueDate[0];
        $dueTime = $dueDate[1];
        $data['due_date'] = $dueDate;
        $data['due_time'] = $dueTime;
        $data['job_for'] = array();

        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } elseif ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }

        return $data;
    }


    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $userTags = "[";
        $first = true;
        foreach ($users as $user) {
            if ($first) {
                $first = false;
            } else {
                $userTags .= ',{"operator": "OR"},';
            }
            $userTags .= '{"key": "email", "relation": "=", "value": "' . strtolower($user->email) . '"}';
        }
        $userTags .= ']';
        return $userTags;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($jobId, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $jobId)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($user);
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;

    }

    /*
     * Function to accept the job with the job id
    */
    public function acceptJobWithId($jobId, $user)
    {
        $job = Job::findOrFail($jobId);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($jobId, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $jobId)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msgText = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = array($user);
                    $this->sendPushNotificationToSpecificUsers($users_array, $jobId, $data, $msgText, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = array();
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($user->is('customer')) {
            $job->withdraw_at = Carbon::now();
            $job->status = $job->withdraw_at->diffInHours($job->due) >= 24 ? 'withdrawbefore24' : 'withdrawafter24';
            $job->save();

            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msgText = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    // send Session Cancel Push to Translator
                    $this->sendPushNotificationToSpecificUsers($users_array, $jobId, $data, $msgText, $this->isNeedToDelayPush($translator->id));
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msgText = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = array($customer);
                        $this->sendPushNotificationToSpecificUsers($users_array, $jobId, $data, $msgText, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
                Job::deleteTranslatorJobRel($translator->id, $jobId);

                $data = $this->jobToData($job);

                // send Push all sutiable translators
                $this->sendNotificationTranslator($job, $data, $translator->id);
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($user)
    {
        $userMeta = $user->userMeta;
        $translatorType = $userMeta->translator_type;
        $jobType = $this->getJobType($translatorType);

        $languages = UserLanguages::where('user_id', '=', $user->id)->get();
        $userLanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;

        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $jobIds = Job::getJobs($user->id, $jobType, 'pending', $userLanguage, $gender, $translatorLevel);

        foreach ($jobIds as $k => $job) {
            $jobUserId = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($user->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($user->id, $job);
            $checkTown = Job::checkTowns($jobUserId, $user->id);

            if(($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') ||
                (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checkTown == false)) {
                unset($jobIds[$k]);
            }
        }

        return $jobIds;
    }

    /**
     * @param array $postData
     */
    public function jobEnd($postData = array())
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $sessionExplode = explode(':', $job->session_time);
        $sessionTime = $sessionExplode[0] . ' tim ' . $sessionExplode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $translator = $job->translatorJobRel->where('completed_at', Null)
            ->where('cancel_at', Null)
            ->first();

        Event::fire(new SessionEnded($job, ($postData['userid'] == $job->user_id) ? $translator->user_id : $job->user_id));

        $user = $translator->user()
            ->first();

        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $translator->completed_at = $completedDate;
        $translator->completed_by = $postData['userid'];
        $translator->save();
    }

    public function endJob($postData)
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        if($jobDetail->status != 'started') {
            return ['status' => 'success'];
        }

        $start = date_create($jobDetail->due);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $sessionExplode = explode(':', $job->session_time);
        $sessionTime = $sessionExplode[0] . ' tim ' . $sessionExplode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $translator = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($postData['user_id'] == $job->user_id) ? $translator->user_id : $job->user_id));

        $user = $translator->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $translator->completed_at = $completedDate;
        $translator->completed_by = $postData['user_id'];
        $translator->save();

        $response['status'] = 'success';
        return $response;
    }

    public function customerNotCall($postData)
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $postData["job_id"];
        $job = Job::with('translatorJobRel')->find($jobId);
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';
        $job->save();

        $translator = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $translator->completed_at = $completedDate;
        $translator->completed_by = $translator->user_id;
        $translator->save();

        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $user = $request->__authenticatedUser;
        $consumerType = $user->consumer_type;
        $allJobs = Job::query();

        if ($user && $user->user_type == env('SUPERADMIN_ROLE_ID')) {
            if (isset($requestData['id']) && $requestData['id'] != '') {
                if (is_array($requestData['id'])) {
                    $allJobs->whereIn('id', $requestData['id']);
                } else {
                    $allJobs->where('id', $requestData['id']);
                }
                $requestData = array_only($requestData, ['id']);
            }

            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestData['lang']);
            }

            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('status', $requestData['status']);
            }

            if (isset($requestData['expired_at']) && $requestData['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $requestData['expired_at']);
            }

            if (isset($requestData['will_expire_at']) && $requestData['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $requestData['will_expire_at']);
            }

            if (isset($requestData['customer_email']) && count($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $requestData['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }

            if (isset($requestData['translator_email']) && count($requestData['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $requestData['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestData['job_type']);
            }

            if (isset($requestData['physical'])) {
                $allJobs->where('customer_physical_type', $requestData['physical']);
                $allJobs->where('ignore_physical', 0);
            }

            if (isset($requestData['phone'])) {
                $allJobs->where('customer_phone_type', $requestData['phone']);
                if(isset($requestData['physical']))
                    $allJobs->where('ignore_physical_phone', 0);
            }

            if (isset($requestData['flagged'])) {
                $allJobs->where('flagged', $requestData['flagged']);
                $allJobs->where('ignore_flagged', 0);
            }

            if (isset($requestData['distance']) && $requestData['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if(isset($requestData['salary']) &&  $requestData['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($requestData['count']) && $requestData['count'] == 'true') {
                $allJobs = $allJobs->count();

                return ['count' => $allJobs];
            }

            if (isset($requestData['consumer_type']) && $requestData['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function($q) use ($requestData) {
                    $q->where('consumer_type', $requestData['consumer_type']);
                });
            }

            if (isset($requestData['booking_type'])) {
                if ($requestData['booking_type'] == 'physical') {
                    $allJobs->where('customer_physical_type', 'yes');
                }

                if ($requestData['booking_type'] == 'phone') {
                    $allJobs->where('customer_phone_type', 'yes');
                }
            }

        } else {
            if (isset($requestData['id']) && $requestData['id'] != '') {
                $allJobs->where('id', $requestData['id']);
                $requestData = array_only($requestData, ['id']);
            }

            if ($consumerType == 'RWS') {
                $allJobs->where('job_type', '=', 'rws');
            } else {
                $allJobs->where('job_type', '=', 'unpaid');
            }

            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestData['lang']);
            }

            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('status', $requestData['status']);
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestData['job_type']);
            }

            if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', '=', $user->id);
                }
            }
        }

        if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
            $allJobs->where('ignore_feedback', '0');
            $allJobs->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });

            if (isset($requestData['count']) && $requestData['count'] != 'false') {
                return ['count' => $allJobs->count()];
            }
        }

        $allJobs->orderBy('created_at', 'desc');

        if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
            if (isset($requestData['from']) && $requestData['from'] != "") {
                $allJobs->where('created_at', '>=', $requestData["from"]);
            }
            if (isset($requestData['to']) && $requestData['to'] != "") {
                $to = $requestData["to"] . " 23:59:00";
                $allJobs->where('created_at', '<=', $to);
            }
            $allJobs->orderBy('created_at', 'desc');
        }

        if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
            if (isset($requestData['from']) && $requestData['from'] != "") {
                $allJobs->where('due', '>=', $requestData["from"]);
            }
            if (isset($requestData['to']) && $requestData['to'] != "") {
                $to = $requestData["to"] . " 23:59:00";
                $allJobs->where('due', '<=', $to);
            }
            $allJobs->orderBy('due', 'desc');
        }


        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        if ($limit == 'all') {
            $allJobs = $allJobs->get();
        } else {
            $allJobs = $allJobs->paginate(15);
        }
        return $allJobs;
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $allCustomers = DB::table('users')->where('user_type', '1')->lists('email');
        $allTranslators = DB::table('users')->where('user_type', '2')->lists('email');

        $user = Auth::user();
        $allJobs = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->whereIn('jobs.id', $jobId);

        if ($user && $user->is('superadmin')) {
            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestData['lang'])
                    ->where('jobs.ignore', 0);
            }
            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestData['status'])
                    ->where('jobs.ignore', 0);
            }
            if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestData['translator_email']) && $requestData['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestData["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestData["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestData['job_type'])
                    ->where('jobs.ignore', 0);
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'allCustomers' => $allCustomers,
            'allTranslators' => $allTranslators,
            'requestData' => $requestData
        ];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $allCustomers = DB::table('users')->where('user_type', '1')->lists('email');
        $allTranslators = DB::table('users')->where('user_type', '2')->lists('email');

        $user = Auth::user();
        $allJobs = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->where('jobs.ignore_expired', 0);

        if ($user && ($user->is('superadmin') || $user->is('admin'))) {
            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestData['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
            }
            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestData['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
            }
            if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestData['translator_email']) && $requestData['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestData["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestData["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestData['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'allCustomers' => $allCustomers,
            'allTranslators' => $allTranslators,
            'requestData' => $requestData
        ];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reOpen($request)
    {
        $jobId = $request['jobid'];
        $userId = $request['userid'];

        $job = Job::find($jobId);
        $job = $job->toArray();

        $data = array();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userId;
        $data['job_id'] = $jobId;
        $data['cancel_at'] = Carbon::now();

        if ($job['status'] !== 'timedout') {

            $dataReopen = array();
            $dataReopen['status'] = 'pending';
            $dataReopen['created_at'] = Carbon::now();
            $dataReopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $dataReopen['created_at']);

            $affectedRow = Job::where('id', '=', $jobId)->update($dataReopen);
            $newJobId = $jobId;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobId;

            $affectedRow = Job::create($job);
            $newJobId = $affectedRow['id'];
        }

        Translator::where('job_id', $jobId)
            ->where('cancel_at', NULL)
            ->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);

        if (isset($affectedRow)) {
            $this->sendNotificationByAdminCancelJob($newJobId);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time
     * @param  string $format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } elseif ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }

    // TODO Below functions should be moved to notification service or handlers

    /*
    * send session start remind notification
    */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $dueExplode = explode(' ', $due);

        $msgText = array(
            "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $dueExplode[1]
                . ' på ' . $dueExplode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
        );

        if ($job->customer_physical_type == 'yes') {
            $msgText = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl '
                    . $dueExplode[1] . ' på ' . $dueExplode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        }

        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers(array($user), $job->id, $data, $msgText, $this->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param array $data
     * @param $excludeUserId
     */
    public function sendNotificationTranslator($job, $excludeUserId, $data = [])
    {
        $users = User::all();

        // suitable translators (no need to delay push)
        $translatorArray = array();

        // suitable translators (need to delay push)
        $delPayTranslatorArray = array();

        foreach ($users as $user) {

            // user is translator and he is not disabled
            if ($user->user_type == '2' && $user->status == '1' && $user->id != $excludeUserId) {
                if (!$this->isNeedToSendPush($user->id)) continue;

                $notGetEmergency = TeHelper::getUsermeta($user->id, 'not_get_emergency');
                if (in_array('yes', [$data['immediate'], $notGetEmergency])) continue;

                // get all potential jobs of this user
                $jobs = $this->getPotentialJobIdsWithUserId($user->id);
                foreach ($jobs as $oneJob) {

                    // one potential job is the same with current job
                    if ($job->id == $oneJob->id) {
                        $userId = $user->id;
                        $jobForTranslator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($jobForTranslator == 'SpecificJob') {
                            $jobChecker = Job::checkParticularJob($userId, $oneJob);
                            if (($jobChecker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($user->id)) {
                                    $delPayTranslatorArray[] = $user;
                                } else {
                                    $translatorArray[] = $user;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msgContents = $data['immediate'] == 'no' ?
            'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'] :
            'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';

        $msgText = array(
            "en" => $msgContents
        );

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translatorArray, $delPayTranslatorArray, $msgText, $data]);

        // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);

        // send new booking push to suitable translators(need to delay)
        $this->sendPushNotificationToSpecificUsers($delPayTranslatorArray, $job->id, $data, $msgText, true);
    }

    /**
     * Sends SMS to translators and returns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ?: $jobPosterMeta->city;

        // analyse whether it's phone or physical; if both = default to phone
        switch (true) {
            // It's a physical job
            case $job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no':
                $message = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);
                break;
            // It's a phone job or both but should be handled as phone job
            case $job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes':
            case $job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes':
                $message = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
                break;
            default:
                $message = '';
        }

        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $jobId
     * @param $data
     * @param $msgText
     * @param $isNeedOfDelay
     */
    public function sendPushNotificationToSpecificUsers($users, $jobId, $data, $msgText, $isNeedOfDelay)
    {

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $jobId, [$users, $data, $msgText, $isNeedOfDelay]);
        if (env('APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $oneSignalApiKey = config('app.prodOnesignalApiKey');
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $oneSignalApiKey = config('app.devOnesignalApiKey');
        }
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", $oneSignalApiKey);


        $userTags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $jobId;
        $iosSound = 'default';
        $androidSound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $androidSound = 'normal_booking';
                $iosSound = 'normal_booking.mp3';
            } else {
                $androidSound = 'emergency_booking';
                $iosSound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($userTags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msgText,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $androidSound,
            'ios_sound'      => $iosSound
        );

        if ($isNeedOfDelay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }
        $fields = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $jobId . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * @param $job
     * @param $currentTranslator
     * @param $newTranslator
     */
    public function sendChangedTranslatorNotification($job, $currentTranslator, $newTranslator)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($currentTranslator) {
            $user = $currentTranslator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $newTranslator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * @param $job
     * @param $oldTime
     */
    public function sendChangedDateNotification($job, $oldTime)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $oldTime
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $oldTime
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }

    /**
     * @param $job
     * @param $oldLang
     */
    public function sendChangedLangNotification($job, $oldLang)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $oldLang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msgText = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msgText, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $jobId
     */
    public function sendNotificationByAdminCancelJob($jobId)
    {
        $job = Job::findOrFail($jobId);
        $userMeta = $job->user->userMeta()->first();
        $data = $this->getJobDataArray($job);
        $data['customer_town'] = $userMeta->city;
        $data['customer_type'] = $userMeta->customer_type;

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } elseif ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        // send Push all sutiable translators
        $this->sendNotificationTranslator($job, $data, '*');
    }

    /**
     * send session start remind notification
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';

        $msgText = array(
            "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
        );

        if ($job->customer_physical_type == 'yes') {
            $msgText = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        }

        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers(array($user), $job->id, $data, $msgText, $this->isNeedToDelayPush($user->id));
        }
    }
}
