<?php

namespace App\Http\Controllers;

use App\MonzoApi;
use App\OAuth\Monzo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

class MonzoController extends Controller
{
    public function reset(Request $request)
    {
        $request->session()->forget('monzo');
        $request->session()->reflash();

        return redirect('/');
    }

    public function resetAccount(Request $request)
    {
        $request->session()->forget('monzo.chosen_account');
        $request->session()->reflash();

        return redirect('/monzo/choose-account');
    }

    public function auth(Request $request, Monzo $monzo)
    {
        $authUrl = $monzo->getAuthorizationUrl();
        $request->session()->put('monzo', [
            'state' => $monzo->getState()
        ]);

        return redirect($authUrl);
    }

    public function redirect(Request $request, Monzo $monzo)
    {
        $state = $request->query('state');
        if (!$state) {
            flash('Something went wrong with your state, please try again.', 'warning');

            return redirect('/monzo/reset');
        }

        $code = $request->input('code');
        if (!$code) {
            flash('Something went wrong with your state, please try again.', 'warning');

            return redirect('/monzo/reset');
        }

        try {
            $accessToken = $monzo->getAccessToken('authorization_code', [
                'code' => $code
            ]);
        } catch (IdentityProviderException $e) {
            flash(
                'Something went horrible wrong linking Monzo, please try again - ' . $e->getMessage(),
                'warning'
            );

            return redirect('/monzo/reset');
        }

        $monzoUser = $monzo->getResourceOwner($accessToken);

        $request->session()->put('monzo', [
            'access_token' => $accessToken->getToken(),
            'refresh_token' => $accessToken->getRefreshToken(),
            'expires' => $accessToken->getExpires(),
            'whoami' => [
                'client_id' => $monzoUser->getClientId(),
                'user_id' => $monzoUser->getUserId(),
            ]
        ]);

        return redirect('/monzo/choose-account');
    }

    public function choose(Request $request)
    {
        $accessToken = $request->session()->get('monzo.access_token');
        $expires = $request->session()->get('monzo.expires');

        if ($expires < time()) {
            flash('Access token is invalid, cannot choose account', 'warning');

            return redirect('/monzo/reset');
        }

        $monzoApi = new MonzoApi($accessToken);

        try {
            $accounts = $monzoApi->getAccounts();
        } catch (\Exception $e) {
            flash('Failed to retrieve accounts, please try again', 'warning');

            return redirect('/'); // TODO: change to /monzo/reset
        }

        $request->session()->put('monzo.accounts', $accounts);

        if (count($accounts) === 1) {
            // only have one account, they don't need to choose - we'll choose for them
            $request->session()->put('monzo.chosen_account.id', $accounts[0]['id']);
            $request->session()->put('monzo.chosen_account.name', $accounts[0]['description']);

            return redirect('/');
        }

        return view('monzo.choose-account', [
            'accounts' => $accounts
        ]);
    }

    public function chosen(Request $request, string $account_id)
    {
        $accounts = $request->session()->get('monzo.accounts');
        if (!$accounts) {
            flash('You aren\'t ready to choose a Monzo account, please try again', 'warning');

            return redirect('/monzo/reset');
        }

        foreach ($accounts as $account) {
            if ($account['id'] == $account_id) {
                $request->session()->put('monzo.chosen_account.id', $account['id']);
                $request->session()->put('monzo.chosen_account.name', $account['description']);

                flash('Monzo account successfully chosen, YNAB now?', 'success');
                return redirect('/');
            }
        }

        flash('Invalid Monzo account chosen, please try again', 'warning');

        return redirect('/');
    }

    public function setupWebhook(Request $request)
    {

    }
}
