<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\FormatPasswordRule;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'username' => 'required|string',
            'password' => ['required','string','min:8','confirmed', new FormatPasswordRule]
        ]);

        try {
            DB::beginTransaction();
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $moodleResponse = $this->registerInMoodle($user);

            switch ($moodleResponse['estatus']) {
                case '200':
                    DB::commit();
                    if (Auth::attempt([
                        'email' => $request->email,
                        'password' => $request->password,
                    ])) {
                        return redirect()->route('dashboard');
                    } else {
                        return redirect()->route('login');
                    }
                    break;

                case '404':
                    DB::rollBack();
                    info($moodleResponse);
                    dd('no se pudo crear en db laravel');
                    break;

                case '500':
                    DB::rollBack();
                    dd('error del servidor segundo try');
                    break;
                default:
                    # code...
                    break;
            }

            if ($moodleResponse['estatus']) {
                DB::commit();
                dd('viene el logeo');
            } else {
                DB::rollBack();
                dd('no se pudo crear en moodle');
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            dd($th->getMessage());
        }

    }

    private function registerInMoodle($user)
    {
        try {
            $request = Http::accept('application/json')->post('http://moodle.test/webservice/rest/server.php?wstoken=ba9512c528591a59a8e41a3144f8ca45&wsfunction=core_user_create_users&moodlewsrestformat=json&users[0][username]=' . request()->username . '&users[0][password]=' . request()->password . '&users[0][firstname]=' . $user->name . '&users[0][lastname]=' . request()->lastname . '&users[0][email]=' . $user->email . '&users[0][auth]=manual&users[0][idnumber]=&users[0][lang]=es_mx&users[0][calendartype]=gregorian');

            $response = $request->json();

            return ['data' => $response, 'estatus' => isset($response[0]['id']) ? 200 : 404];

        } catch (\Throwable $th) {

            return ['data' => $th->getMessage(), 'estatus' => 500];
            
        }
    }

    public function logout()
    {
        Auth::logout();
        return redirect()->route('login');
    }

    public function singin(Request $request)
    {
        if (Auth::attempt([
            'email' => request()->email,
            'password' => request()->password,
        ])) {
            return redirect()->route('moodle');
        } else {
            return redirect()->route('login');
        }
    }


    public function moodle()
    {
        return view('moodle');
    }

    private function generateMoodleUser($username, $email)
    {
        $client = new Client();
        $moodleUrl = 'http://moodle.test/webservice/rest/server.php';
        $token = '389eb752ee2df29d21122402029af701';

        try {
            $response = $client->post($moodleUrl,[
                'form_params' => [
                    'wstoken' => $token,
                    'wsfunction' => 'auth_userkey_request_user_key',
                    'moodlewsrestformat' => 'json',
                    'user' => $username,
                    'service' => 'auth_userkey',
                    'context' => 'Your context',
                    'validuntil' => strtotime('+1 hour')
                ]
            ]);

            $data = json_decode($response->getBody(),true);
            return $data['userkey'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function loginToMoodle(Request $request)
    {
        $user = Auth::user();
        $userKey = $this->generateMoodleUser($user->username, $user->email);

        if($userKey)
        {
            $moodleLoginUrl = 'http://moodle.test/auth/userkey/login.php?key='. $userKey;
            return redirect($moodleLoginUrl);
        }

        return redirect()->back()->with('error','No se pudo iniciar sesión en Moodle');
    }

}
