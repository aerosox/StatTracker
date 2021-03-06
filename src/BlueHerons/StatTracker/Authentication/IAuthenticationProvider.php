<?php
namespace BlueHerons\StatTracker\Authentication;

use BlueHerons\StatTracker\StatTracker;

interface IAuthenticationProvider {

    /**
     * Process a login request for Stat Tracker.
     *
     * This function MUST return a PHP object with the following properties:
     * - "status": ["authentication_required", "registration_required", "okay"]
     *     - "authentication_required": When there is no session, and the user needs to log in.
     *    - "registration_required": When the user has successfully authenticated, but has not completed
     *       registration.
     *    - "okay": When the user exists, has completed registration, successfully  authenticated, and a session
     *       has been created.
     * - "email": The email address provided via the Authentication Provider
     * - "error": true or false
     *    - ONLY TRUE WHEN a application error occurred, false otherwise
     * - "url": URL to direct the user to for authentication.
     *    - ONLY REQUIRED if "status" is "authentication_required"
     * - "agent" Agent object
     *    - ONLY REQUIRED if "status" is "okay".
     *
     * Examples (provided in JSON for readability)
     *
     * - User needs to authenticate
     * {"error": false, "status": "authentication_required", "url": "http://account.google.com/login"}
     *
     * - User has successfuly authenticated, but they have not completed registration
     * {"error": false, "status": "registration_required", "email": "agent_email@gmail.com"}
     *
     * - Some error occurred, and the user cannot do anything to fix it
     * {"error": true, "message": "Google isn't available"}
     *
     * - Successful authentication
     * {"error": false, "status": "okay", "agent": { ... }}
     */
    public function login(StatTracker $app);

    /**
     * Process a logout request for Stat Tracker. The user session MUST be destroyed inside this method.
     *
     * This function MUST return a PHP object with the following properties:
     * - "error": true or false
     *    - true if a application error occurred. The "message" property is also required in this case.
     * - "message": <user description of error>
     *    - Message that will be displayed to the user if "error" is true.
     * - "status": "logged_out"
     *    - ONLY if "error" is false.
     *
     * Examples (provided in JSON for readibility)
     *
     * - User successfully logged out
     *    {"error": false, "status": "logged_out"}
     *
     * - Error during the logot process
     *    {"error": true, "message": "Google didn't respond to the logout request"}
     */
    public function logout(StatTracker $app);

    /**
     * This method processes the callback from the Authentication provider. It should be passthrough, as the user
     * will be redirected to a page that calls this method (/authenticate?action=callback). Ideally, save session
     * info from the provider here, and process it via the login() method, which will be called automatically.
     */
    public function callback(StatTracker $app);

    /**
     * When status == "registration_required", optionally send an email to the user stating what needs to be completed
     * in order to complete registration.
     *
     * Return the entire body of an email message that shouold be sent to the user.
     *
     * If no email should be sent, return false
     */
    public function getRegistrationEmail($email_address);

    public function getAuthenticationUrl();

    public function getName();
}

class AuthResponse {

    const AUTHENTICATION_REQUIRED = "authentication_required";
    const LOGGED_OUT = "logged_out";
    const OKAY = "okay";
    const REGISTRATION_REQUIRED = "registration_required";

    public static function authenticationRequired(IAuthenticationProvider $provider) {
        return new AuthResponse(self::AUTHENTICATION_REQUIRED, array(
                                    "providers" => array(
                                        array(
                                            "name" => strtolower($provider->getName()),
                                            "url" => $provider->getAuthenticationUrl()
                                        )
                                    )
                                ));
    }

    public static function loggedOut() {
        return new AuthResponse(self::LOGGED_OUT);
    }

    public static function okay($agent) {
        return new AuthResponse(self::OKAY, array(
                                    "agent" => $agent
                                ));
    }

    public static function registrationRequired($message = "", $email_address = "") {
        return new AuthResponse(self::REGISTRATION_REQUIRED, array(
                                    "message" => $message,
                                    "email" => $email_address
                                ));
    }

    public static function error($message) {
        return new AuthResponse(null, array(
                                    "message" => $message
                                ), true);
    }

    private function __construct($status, $fields = array(), $error = false) {
        $this->error = $error;
        $this->status = $status;

        foreach ($fields as $k => $v) {
            $this->$k = $v;
        }
    }

}
