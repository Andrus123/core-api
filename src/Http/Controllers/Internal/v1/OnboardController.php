<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Events\AccountCreated;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\OnboardRequest;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class OnboardController extends Controller
{
    /**
     * Checks to see if this is the first time Fleetbase is being used by checking if any organizations exists.
     *
     * @return \Illuminate\Http\Response
     */
    public function shouldOnboard()
    {
        return response()->json(
            [
                'should_onboard' => Company::doesntExist(),
            ]
        );
    }

    /**
     * Onboard a new account and send send to verify email.
     *
     * @return \Illuminate\Http\Response
     */
    public function createAccount(OnboardRequest $request)
    {
        // if first user make admin
        $isAdmin = !User::exists();

        // Get user properties
        $name     = $request->input('name');
        $email    = $request->input('email');
        $phone    = $request->input('phone');
        $username = Str::slug($name . Str::random(3), '_');

        // create user account
        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'phone'    => $phone,
            'username' => $username,
            'status'   => 'active',
        ]);

        // set the user password
        $user->password = $request->input('password');

        // set the user type
        $user->setUserType($isAdmin ? 'admin' : 'user');

        // create company
        $company = new Company(['name' => $request->input('organization_name')]);
        $company->setOwner($user)->save();

        // assign user to organization
        $user->assignCompany($company);

        // create company user
        CompanyUser::create([
            'user_uuid'    => $user->uuid,
            'company_uuid' => $company->uuid,
            'status'       => 'active',
        ]);

        // create verification code
        try {
            VerificationCode::generateEmailVerificationFor($user);
        } catch (\Throwable $e) {
            // If phone number is supplied send via SMS
            if ($user->phone) {
                try {
                    VerificationCode::generateSmsVerificationFor($user);
                } catch (\Throwable $e) {
                    // silence
                }
            }
        }

        // send account created event
        event(new AccountCreated($user, $company));

        // create auth token
        $token = $user->createToken($user->uuid);

        return response()->json([
            'status'           => 'success',
            'session'          => base64_encode($user->uuid),
            'token'            => $isAdmin ? $token->plainTextToken : null,
            'skipVerification' => $isAdmin,
        ]);
    }

    /**
     * Send/Resend verification email.
     *
     * @param \\Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response $response
     */
    public function sendVerificationEmail(Request $request)
    {
        $id        = $request->input('id');
        $decodedId = base64_decode($id);

        // Get user using id
        $user = User::where('uuid', $decodedId)->first();

        if ($user) {
            // create verification code
            VerificationCode::generateEmailVerificationFor($user);
        } else {
            return response()->error('No user found with provided email address.');
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }

    /**
     * Send/Resend verification SMS.
     *
     * @param \\Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response $response
     */
    public function sendVerificationSms(Request $request)
    {
        $id        = $request->input('id');
        $decodedId = base64_decode($id);

        // Get user using id
        $user = User::where('uuid', $decodedId)->first();

        if ($user) {
            // create verification code
            VerificationCode::generateSmsVerificationFor($user);
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }

    /**
     * Verfiy and validate an email address with code.
     *
     * @param \\Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response $response
     */
    public function verifyEmail(Request $request)
    {
        // users uuid as session
        $session = $request->input('session');
        $code    = $request->input('code');

        // decode session
        if (!Str::isUuid($session)) {
            $session = base64_decode($session);
        }

        // if still not valid check session
        if (!Str::isUuid($session)) {
            $session = session('user');
        }

        // make sure session is found
        if (!$session) {
            return response()->error('No session to verify email for.');
        }

        // get verification code for session
        $verifyCode = VerificationCode::where([
            'subject_uuid' => $session,
            'for'          => 'email_verification',
            'code'         => $code,
        ])->first();

        // check if sms verification
        if (!$verifyCode) {
            $verifyCode = VerificationCode::where([
                'subject_uuid' => $session,
                'for'          => 'phone_verification',
                'code'         => $code,
            ])->first();
        }

        // no verification code found
        if (!$verifyCode) {
            return response()->error('Invalid verification code.');
        }

        // get user
        $user = $request->user();
        if (!$user) {
            $user = User::where('uuid', $session)->first();
        }

        // Handle no user
        if (!$user) {
            return response()->error('No user found using this email.');
        }

        // get verify time
        $verifiedAt = Carbon::now();

        // verify users email address or phone depending
        if ($verifyCode->for === 'email_verification') {
            $user->email_verified_at = $verifiedAt;
        } elseif ($verifyCode->for === 'phone_verification') {
            $user->phone_verified_at = $verifiedAt;
        }

        $user->status = 'active';
        $user->updateLastLogin();
        $token = $user->createToken($user->uuid);

        return response()->json([
            'status'      => 'ok',
            'verified_at' => $verifiedAt,
            'token'       => $token->plainTextToken,
        ]);
    }
}
