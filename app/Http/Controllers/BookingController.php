<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $response = [];
        if ($request->has('user_id')) {
            $response = $this->repository->getUsersJobs($request->get('user_id'));
        } elseif (in_array($request->__authenticatedUser->user_type, [env('ADMIN_ROLE_ID'), env('SUPERADMIN_ROLE_ID')])) {
            $response = $this->repository->getAll($request);
        }

        return response($response);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return response($job);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->store($request->__authenticatedUser, $data);

        return response($response);

    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;
        $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->storeJobEmail($data);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        if ($request->has('user_id')) {
            $response = $this->repository->getUsersJobsHistory($request->get('user_id'), $request);
            return response($response);
        }

        return response([]);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;
        $response = $this->repository->acceptJob($data, $user);

        return response($response);
    }

    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = $request->__authenticatedUser;
        $response = $this->repository->acceptJobWithId($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;
        $response = $this->repository->cancelJobAjax($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->endJob($data);

        return response($response);

    }

    public function customerNotCall(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->customerNotCall($data);

        return response($response);

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $user = $request->__authenticatedUser;
        $response = $this->repository->getPotentialJobs($user);

        return response($response);
    }

    public function distanceFeed(Request $request)
    {
        $data = $request->all();
        if ($data['flagged'] == 'true') {
            if ($data['admincomment'] == '') {
                return "Please, add comment";
            }
            $flagged = 'yes';
        } else {
            $flagged = 'no';
        }


        $jobId = isset($data['jobid']) && $data['jobid'] != "" ? $data['jobid'] : null;
        $time = isset($data['time']) && $data['time'] != "" ? $data['time'] : "";
        $distance = isset($data['distance']) && $data['distance'] != "" ? $data['distance'] : "";
        if (($time || $distance) && $jobId) {
            Distance::where('job_id', '=', $jobId)
                ->update(array('distance' => $distance, 'time' => $time));
        }

        $manuallyHandled = $data['manually_handled'] == 'true' ? 'yes' : 'no';
        $byAdmin = $data['by_admin'] == 'true' ? 'yes' : 'no';
        $adminComment = isset($data['admincomment']) && $data['admincomment'] != "" ? $data['admincomment'] : "";
        $session = isset($data['session_time']) && $data['session_time'] != "" ? $data['session_time'] : "";

        if ($adminComment || $session) {

            Job::where('id', '=', $jobId)
                ->update(array(
                        'admin_comments' => $adminComment,
                        'flagged' => $flagged,
                        'session_time' => $session,
                        'manually_handled' => $manuallyHandled,
                        'by_admin' => $byAdmin
                    )
                );

        }

        return response(['status' => 'success', 'message' => 'Record updated!']);
    }

    public function reOpen(Request $request)
    {
        $data = $request->all();
        $isReopened = $this->repository->reOpen($data);

        $response = ['status' => 'error', 'message' => 'Please try again.'];

        if ($isReopened) {
            $response = ['status' => 'success', 'message' => 'Tolk cancelled!'];
        }

        return response($response);
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $jobData = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, '*', $jobData);

        return response(['status' => 'success', 'message' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response([ 'status' => 'success', 'message' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

}
