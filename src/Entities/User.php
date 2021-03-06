<?php

namespace IonAuth\IonAuth\Entities;

use IonAuth\IonAuth\Helper;
use IonAuth\IonAuth\Utilities\Collection\CollectionItem;
use IonAuth\IonAuth\Utilities\Collection\GroupCollection;
use IonAuth\IonAuth\Repositories\UserRepository;

class User implements CollectionItem
{
    /**
     * account status ('not_activated', etc ...)
     *
     * @var string
     **/
    protected $status;

    private $email;
    private $groups;
    private $id;
    private $last_name;
    private $last_login;

    /**
     * Constructor
     */
    function __construct($config, $db)
    {
        $this->userRepository = new UserRepository($config, $db);
        $this->groups = GroupCollection::create([]);
    }

    /**
     * getId
     */
    public function getId()
    {
        return $this->id;
    }


    /**
     * forgotten password feature
     *
     * @param $identity
     * @return mixed  boolean / array
     */
    public function forgottenPassword($identity)
    {
        if ($this->ionAuthModel->forgottenPassword($identity)) // changed
        {
            // Get user information
            $user = $this->userRepository->get($this->config->['identity']), $identity)->row();

            if ($user)
            {
                $data = array(
                    'identity'                => $user->{$this->config['identity']},
                    'forgotten_password_code' => $user->forgottenPasswordCode,
                );

                if (!$this->config['useDefaultEmail'])
                {
                    $this->setMessage('forgotPasswordSuccessful');
                    return $data;
                }
                else
                {
                    $message = $this->load->view(
                        $this->config['emailTemplates'] . $this->config['emailForgotPassword'],
                        $data,
                        true
                    );
                    $this->email->clear();
                    $this->email->from($this->config['adminEmail'], $this->config['siteTitle']);
                    $this->email->to($user->email);
                    $this->email->subject(
                        $this->config['siteTitle'] . ' - ' . $this->lang->line('emailForgottenPasswordSubject')
                    );
                    $this->email->message($message);

                    if ($this->email->send())
                    {
                        $this->setMessage('forgotPasswordSuccessful');
                        return true;
                    }
                    else
                    {
                        $this->setError('forgotPasswordUnsuccessful');
                        return false;
                    }
                }
            }
            else
            {
                $this->setError('forgotPasswordUnsuccessful');
                return false;
            }
        }
        else
        {
            $this->setError('forgotPasswordUnsuccessful');
            return false;
        }
    }

    /**
     * forgotten_password_complete()
     * ------------------------------
     * @param $code
     * @return void
     */
    public function forgottenPasswordComplete($code)
    {
        $this->ionAuthModel->triggerEvents('prePasswordChange');

        $identity = $this->config['identity'];
        $profile = $this->where('forgotten_password_code', $code)->users()->row(); //pass the code to profile

        if (!$profile)
        {
            $this->ionAuthModel->triggerEvents(array('postPasswordChange', 'passwordChangeUnsuccessful'));
            $this->setError('passwordChangeUnsuccessful');
            return false;
        }

        $newPassword = $this->ionAuthModel->forgottenPasswordComplete($code, $profile->salt);

        if ($newPassword)
        {
            $data = array(
                'identity' => $profile->{$identity},
                'new_password' => $newPassword
            );

            if (!$this->config['useDefaultEmail'])
            {
                $this->setMessage('passwordChangeSuccessful');
                $this->ionAuthModel->triggerEvents(array('postPasswordChange', 'passwordChangeSuccessful'));
                return $data;
            }
            else
            {
                $message = $this->load->view(
                    $this->config['emailTemplates'] . $this->config['emailForgotPasswordComplete'],
                    $data,
                    true
                );

                $this->email->clear();
                $this->email->from($this->config['adminEmail'], $this->config['siteTitle']);
                $this->email->to($profile->email);
                $this->email->subject(
                    $this->config['siteTitle'] . ' - ' . $this->lang->line('emailNewPasswordSubject')
                );
                $this->email->message($message);

                if ($this->email->send())
                {
                    $this->setMessage('passwordChangeSuccessful');
                    $this->ionAuthModel->triggerEvents(array('postPasswordChange', 'passwordChangeSuccessful'));
                    return true;
                }
                else
                {
                    $this->setError('passwordChangeUnsuccessful');
                    $this->ionAuthModel->triggerEvents(array('postPasswordChange', 'passwordChangeUnsuccessful'));
                    return false;
                }

            }
        }

        $this->ionAuthModel->triggerEvents(array('postPasswordChange', 'passwordChangeUnsuccessful'));
        return false;
    }


    /**
     * forgotten_password_check()
     * --------------------------
     * @param $code
     * @return void
     */
    public function forgottenPasswordCheck($code)
    {
        $profile = $this->where('forgotten_password_code', $code)->users()->row(); //pass the code to profile

        if (!is_object($profile))
        {
            $this->setError('passwordChangeUnsuccessful');
            return false;
        }
        else
        {
            if ($this->config['forgotPasswordExpiration'] > 0)
            {
                //Make sure it isn't expired
                $expiration = $this->config['forgotPasswordExpiration'];
                if (time() - $profile->forgotten_password_time > $expiration)
                {
                    //it has expired
                    $this->clearForgottenPasswordCode($code);
                    $this->setError('passwordChangeUnsuccessful');
                    return false;
                }
            }

            return $profile;
        }
    }


    /**
     * logout()
     * --------------------
     * @return void
     **/
    public function logout()
    {
        $this->ionAuthModel->triggerEvents('logout');

        $identity = $this->config['identity'];
        $this->session->unset_userdata(
            array(
                $identity => '',
                'id' => '',
                'user_id' => ''
            )
        );

        // delete the remember me cookies if they exist
        if ($this->ionAuthModel->getCookie('identity'))
        {
            $this->ionAuthModel->deleteCookie('identity');
        }

        if ($this->ionAuthModel->getCookie('remember_code'))
        {
            $this->ionAuthModel->deleteCookie('remember_code');
        }

        // Destroy the session
        $this->session->sess_destroy();

        $this->setMessage('logoutSuccessful');

        return true;
    }

    /**
     * logged_in
     * -----------------
     * @return bool
     **/
    public function loggedIn()
    {
        $this->ionAuthModel->triggerEvents('loggedIn');
        return (bool)isset($_SESSION['identity']) ? true : false;
    }

    /**
     * logged in
     * --------------------
     * @return integer
     **/
    public function getUserId()
    {
        $userId = $_SESSION['userId'];

        if (!empty($userId))
        {
            return $userId;
        }

        return null;
    }


    /**
     * is admin
     * ---------------------
     * @param bool $id
     * @return bool
     */
    public function isAdmin($id = false)
    {
        $this->ionAuthModel->triggerEvents('isAdmin');

        $adminGroup = $this->config['adminGroup'];

        return $this->inGroup($adminGroup, $id);
    }


    /**
     * in group
     * --------------------------
     * @param mixed group(s) to check
     *
     * @return bool
     **/
    public function inGroup(Group $group)
    {
        return in_array($group->getID(), $this->groups->getKeys());
    }


    /**
     * Activation functions
     *
     * Activate : Validates and removes activation code.
     * Deactivae : Updates a users row with an activation code.
     *
     */

    /**
     * activate
     *
     * @return void
     **/
    public function activate($id, $code = false)
    {
        $this->events->trigger('pre_activate');

        if ($code !== false)
        {
            $query = $this->db->select($this->identityColumn)
                ->where('activation_code', $code)
                ->take(1)
                ->get($this->tables['users']);

            $result = $query->first();

            if (count($query) !== 1)
            {
                $this->events->trigger(array('postActivate', 'postActivateUnsuccessful'));
                $this->setError('activateUnsuccessful');
                return false;
            }

            $identity = $result->{$this->identityColumn};

            $data = array(
                'activationCode' => null,
                'active' => 1
            );

            $this->events->trigger('extraWhere');
            $this->db->update($this->tables['users'], $data, array($this->identityColumn => $identity));
        }
        else
        {
            $data = array(
                'activation_code' => null,
                'active' => 1
            );

            $this->events->trigger('extraWhere');
            $this->db->update($this->tables['users'], $data, array('id' => $id));
        }


        if ($this->db->affected_rows() == 1)
        {
            $this->events->trigger(array('postActivate', 'postActivateSucessful'));
            $this->setMessage('activateSuccessful');
        }
        else
        {
            $this->events-trigger(array('postActivate', 'postActivateUnsuccessful'));
            $this->setError('activateUnsuccessful');
        }


//        return $return;
    }


    /**
     * Deactivate
     *
     * @return void
     **/
    public function deactivate($id = null)
    {
        $this->events->trigger('deactivate');

        if (!isset($id))
        {
            $this->setError('deactivateUnsuccessful');
            return false;
        }

        $activationCode = $this->salt();
        $this->activationCode = $activationCode;

        $data = array(
            'activation_code' => $activationCode,
            'active' => 0
        );

        $this->events->trigger('extraWhere');
        $this->db->update($this->tables['users'], $data, array('id' => $id));

        $return = $this->db->affected_rows() == 1;
        if ($return)
        {
            $this->setMessage('deactivateSuccessful');
        }
        else
        {
            $this->setError('deactivateUnsuccessful');
        }

        return $return;
    }


    public function clearForgottenPasswordCode($code)
    {

        if (empty($code))
        {
            return false;
        }

        $this->db->where('forgottenPasswordCode', $code);

        if ($this->db->count_all_results($this->tables['users']) > 0)
        {
            $data = array(
                'forgottenPasswordCode' => null,
                'forgottenPasswordTime' => null
            );

            $this->db->update($this->tables['users'], $data, array('forgottenPasswordCode' => $code));

            return true;
        }

        return false;
    }

    /**
     * reset password
     *
     * @return bool
     **/
    public function resetPassword($identity, $new)
    {

        $this->events->trigger('preChangePassword');

        if (!$this->identityCheck($identity))
        {
            $this->events->trigger(array('postChangePassword', 'postChangePasswordUnsuccessful'));
            return false;
        }

        $this->events->trigger('extraWhere');

        $query = $this->db->select('id, password, salt')
            ->where($this->identityColumn, $identity)
            ->take(1)
            ->get($this->tables['users']);

        if (count($query) !== 1)
        {
            $this->events->trigger(array('postChangePassword', 'postChangePasswordUnsuccessful'));
            $this->setError('passwordChangeUnsuccessful');
            return false;
        }

        $result = $query->first();

        $new = $this->hashPassword($new, $result->salt);

        //store the new password and reset the remember code so all remembered instances have to re-login
        //also clear the forgotten password code
        $data = array(
            'password' => $new,
            'remember_code' => null,
            'forgotten_password_code' => null,
            'forgotten_password_time' => null,
        );

        $this->events->trigger('extraWhere');
        $this->db->update($this->tables['users'], $data, array($this->identityColumn => $identity));

        $return = $this->db->affected_rows() == 1;
        if ($return)
        {
            $this->events->trigger(array('postChangePassword', 'postChangePasswordSuccessful'));
            $this->setMessage('passwordChangeSuccessful');
        }
        else
        {
            $this->events->trigger(array('postChangePassword', 'postChangePasswordUnsuccessful'));
            $this->setError('passwordChangeUnsuccessful');
        }


        return $return;

    }

    /**
     * change password
     *
     * @return bool
     **/
    public function changePassword($identity, $old, $new)
    {
        $this->events->trigger('preChangePassword');

        $this->events->trigger('extraWhere');

        $query = $this->db->select('id, password, salt')
            ->where($this->identityColumn, $identity)
            ->take(1)
            ->get($this->tables['users']);

        if (count($query) !== 1)
        {
            $this->events->trigger(array('postChangePassword', 'postChangePasswordUnsuccessful'));
            $this->setError('passwordChangeUnsuccessful');
            return false;
        }

        $user = $query->first();

        $oldPasswordMatches = $this->hashPasswordDb($user->id, $old);

        if ($oldPasswordMatches === true)
        {
            //store the new password and reset the remember code so all remembered instances have to re-login
            $hashedNewPassword = $this->hashPassword($new, $user->salt);
            $data = array(
                'password' => $hashedNewPassword,
                'remember_code' => null,
            );

            $this->events->trigger('extra_where');

            $successfullyChangedPasswordInDb = $this->db->update(
                $this->tables['users'],
                $data,
                array($this->identityColumn => $identity)
            );
            if ($successfullyChangedPasswordInDb)
            {
                $this->events->trigger(array('postChangePassword', 'postChangePassword_Successful'));
                $this->setMessage('passwordChangeSuccessful');
            }
            else
            {
                $this->events>trigger(array('postChangePassword', 'postChangePasswordUnsuccessful'));
                $this->setError('passwordChangeUnsuccessful');
            }

            return $successfullyChangedPasswordInDb;
        }

        $this->setError('passwordChangeUnsuccessful');
        return false;
    }

    /**
     * Checks username
     *
     * @return bool
     **/
    public function usernameCheck($username = '')
    {
        $this->events->trigger('usernameCheck');

        if (empty($username))
        {
            return false;
        }

        $this->events->trigger('extra_where');

        return $this->db->where('username', $username)
            ->count_all_results($this->tables['users']) > 0;
    }

    /**
     * Checks email
     *
     * @return bool
     **/
    public function emailCheck($email = '')
    {
        $this->events->trigger('email_check');

        if (empty($email))
        {
            return false;
        }

        $this->events->trigger('extra_where');

        return $this->db->where('email', $email)
            ->count_all_results($this->tables['users']) > 0;
    }

    /**
     * Identity check
     *
     * @return bool
     **/
    public function identityCheck($identity = '')
    {
        $this->events->trigger('identity_check');

        if (empty($identity))
        {
            return false;
        }

        return $this->db->where($this->identityColumn, $identity)
            ->count_all_results($this->tables['users']) > 0;
    }

    /**
     * Insert a forgotten password key.
     *
     * @return bool
     **/
    public function _forgottenPassword($identity)
    {
        if (empty($identity))
        {
            $this->events->trigger(array('postForgottenPassword', 'postForgottenPasswordUnsuccessful'));
            return false;
        }

        //All some more randomness
        $activationCodePart = "";
        if (function_exists("openssl_random_pseudo_bytes"))
        {
            $activationCodePart = openssl_random_pseudo_bytes(128);
        }

        for ($i = 0; $i < 1024; $i++)
        {
            $activationCodePart = sha1($activationCodePart . mt_rand() . microtime());
        }

        $key = $this->hash_code($activationCodePart . $identity);

        $this->forgottenPasswordCode = $key;

        $this->events->trigger('extraWhere');

        $update = array(
            'forgotten_password_code' => $key,
            'forgotten_password_time' => time()
        );

        $this->db->update($this->tables['users'], $update, array($this->identityColumn => $identity));

        $return = $this->db->affected_rows() == 1;

        if ($return)
        {
            $this->events->trigger(array('postForgottenPassword', 'postForgottenPasswordSuccessful'));
        }
        else
        {
            $this->events->trigger(array('postForgottenPassword', 'postForgottenPasswordUnsuccessful'));
        }

        return $return;
    }

    /**
     * Forgotten Password Complete
     *
     * @return string
     **/
    public function _forgottenPasswordComplete($code, $salt = false)
    {
        $this->events->trigger('preForgottenPasswordComplete');

        if (empty($code))
        {
            $this->events->trigger(array('postForgottenPasswordComplete', 'postForgottenPasswordCompleteUnsuccessful'));
            return false;
        }

        $profile = $this->where('forgottenPasswordCode', $code)->users()->first(); //pass the code to profile

        if ($profile)
        {

            if ($this->config['forgotPasswordExpiration'] > 0)
            {
                //Make sure it isn't expired
                $expiration = $this->config['forgotPasswordExpiration'];
                if (time() - $profile->forgotten_password_time > $expiration)
                {
                    //it has expired
                    $this->setError('forgotPasswordExpired');
                    $this->events->trigger(
                        array('postForgottenPasswordComplete', 'postForgottenPasswordCompleteUnsuccessful')
                    );
                    return false;
                }
            }

            $password = $this->salt();

            $data = array(
                'password' => $this->hashPassword($password, $salt),
                'forgotten_password_code' => null,
                'active' => 1,
            );

            $this->db->update($this->tables['users'], $data, array('forgotten_password_code' => $code));

            $this->events->trigger(array('postForgottenPasswordComplete', 'postForgottenPasswordCompleteSuccessful'));
            return $password;
        }

        $this->events->trigger(array('postForgottenPasswordComplete', 'postForgottenPasswordCompleteUnsuccessful'));
        return false;
    }

    /**
     * login
     *
     * @return bool
     * @author Mathew
     **/
    public function login($identity, $password, $remember = false)
    {
        $this->events->trigger('preLogin');

        if (empty($identity) || empty($password))
        {
            $this->setError('loginUnsuccessful');
            return false;
        }

        $this->events->trigger('extraWhere');

        $query = $this->db->table($this->config['tables']['users'])
            ->select(
                array(
                    'id',
                    $this->config['identity'],
                    'username',
                    'email',
                    'id',
                    'password',
                    'active',
                    'last_login'
                )
            )
            ->where($this->config['identity'], $identity)
            ->take(1);


        if ($this->isTimeLockedOut($identity))
        {
            //Hash something anyway, just to take up time
            $this->hashPassword($password);

            $this->events->trigger('postLoginUnsuccessful');
            $this->setError('loginTimeout');

            return false;
        }


        $user = $query->first();

        if (isset($user) === true)
        {

            echo 'here:';
            var_dump($user->id, $password);
            $password = $this->hashPasswordDb($user->id, $password);

            echo '------';
            if ($password === true)
            {
                if ($user->active == 0)
                {
                    $this->events->trigger('post_login_unsuccessful');
                    $this->setError('login_unsuccessful_not_active');

                    return false;
                }

                $this->setSession($user);

                $this->updateLastLogin($user->id);

                $this->clearLoginAttempts($identity);

                if ($remember && $this->config['remember_users'])
                {
                    $this->rememberUser($user->id);
                }

                $this->events->trigger(array('postLogin', 'postLoginSuccessful'));
                $this->setMessage('loginSuccessful');

                return true;
            }
        }

        //Hash something anyway, just to take up time
        $this->hashPassword($password);

        $this->increaseLoginAttempts($identity);

        $this->events>trigger('postLoginUnsuccessful');
        $this->setError('loginUnsuccessful');

        return false;
    }

    /**
     * is_max_login_attempts_exceeded
     * Based on code from Tank Auth, by Ilya Konyukhov (https://github.com/ilkon/Tank-Auth)
     *
     * @param string $identity
     * @return boolean
     **/
    public function isMaxLoginAttemptsExceeded($identity)
    {
        if ($this->config['trackLoginAttempts'])
        {
            $maxAttempts = $this->config['maximumLoginAttempts'];
            if ($maxAttempts > 0)
            {
                $attempts = $this->getAttemptsNum($identity);
                return $attempts >= $maxAttempts;
            }
        }
        return false;
    }

    /**
     * Function is TimeLockedOut()
     * ---------------------------------------------------------------------
     * Get a boolean to determine if an account should be locked out due to
     * exceeded login attempts within a given period
     *
     * @param     string , $identity
     * @return    boolean
     */
    public function isTimeLockedOut($identity)
    {

        return $this->isMaxLoginAttemptsExceeded($identity) && $this->getLastAttemptTime($identity) > time(
        ) - $this->config['lockout_time'];
    }

    /**
     * Function: getLastAttemptTime()
     * --------------------------------------
     * Get the time of the last time a login attempt occured from given IP-address or identity
     *
     * @param    string $identity
     * @return    int
     */


    /**
     * users
     *
     * @return object Users
     **/
    public function all($groups = null)
    {
        $this->events->trigger('users');

        if (isset($this->_ionSelect) && !empty($this->_ionSelect))
        {
            foreach ($this->_ionSelect as $select)
            {
                $this->db->select($select);
            }

            $this->_ionSelect = array();
        }
        else
        {
            //default selects
            $this->db->select(
                array(
                    $this->tables['users'] . '.*',
                    $this->tables['users'] . '.id as id',
                    $this->tables['users'] . '.id as user_id'
                )
            );
        }

        //filter by group id(s) if passed
        if (isset($groups))
        {
            //build an array if only one group was passed
            if (is_numeric($groups))
            {
                $groups = Array($groups);
            }

            //join and then run a where_in against the group ids
            if (isset($groups) && !empty($groups))
            {
                $this->db->distinct();
                $this->db->join(
                    $this->tables['usersGroups'],
                    $this->tables['usersGroups'] . '.' . $this->join['users'] . '=' . $this->tables['users'] . '.id',
                    'inner'
                );

                $this->db->where_in($this->tables['usersGroups'] . '.' . $this->join['groups'], $groups);
            }
        }

        $this->events->trigger('extraWhere');

        //run each where that was passed
        if (isset($this->_ionWhere) && !empty($this->_ionWhere))
        {
            foreach ($this->_ionWhere as $where)
            {
                $this->db->where($where);
            }

            $this->_ionWhere = array();
        }

        if (isset($this->_ionLike) && !empty($this->_ionLike))
        {
            foreach ($this->_ionLike as $like)
            {
                $this->db->orLike($like);
            }

            $this->_ionLike = array();
        }

        if (isset($this->_ionLimit) && isset($this->_ionOffset))
        {
            $this->db->take($this->_ionLimit, $this->_ionOffset);

            $this->_ionLimit = null;
            $this->_ionOffset = null;
        }
        else
        {
            if (isset($this->_ionLimit))
            {
                $this->db->take($this->_ionLimit);

                $this->_ionLimit = null;
            }
        }

        //set the order
        if (isset($this->_ionOrderBy) && isset($this->_ionOrder))
        {
            $this->db->order_by($this->_ionOrderBy, $this->_ionOrder);

            $this->_ionOrder = null;
            $this->_ionOrderBy = null;
        }

        $this->response = $this->db->get($this->tables['users']);

        return $this;
    }

    /**
     * user
     *
     * @return IonAuth\IonAuth\Entities\User
     **/
    public function find($id)
    {
        $this->events->trigger('user');

        $this->take(1);
        $this->where($this->tables['users'] . '.id', $id);

        $this->users();

        return $this;
    }

    /**
     * update
     *
     * @return bool
     * @author Phil Sturgeon
     **/
    public function update()
    {
//        $this->triggerEvents('preUpdateUser');

//        $this->db->trans_begin();

        if (array_key_exists($this->identityColumn, $data) && $this->identityCheck(
                $data[$this->identityColumn]
            ) && $user->{$this->identityColumn} !== $data[$this->identityColumn]
        )
        {
            $this->db->trans_rollback();
            $this->setError('accountCreationDuplicate' . ucwords($this->identityColumn));

            $this->events->trigger(array('postUpdateUser', 'postUpdateUserUnsuccessful'));
            $this->setError('updateUnsuccessful');

            return false;
        }

        // Filter the data passed
        $data = $this->_filterData($this->tables['users'], $data);

        if (array_key_exists('username', $data) || array_key_exists('password', $data) || array_key_exists(
                'email',
                $data
            )
        )
        {
            if (array_key_exists('password', $data))
            {
                if (!empty($data['password']))
                {
                    $data['password'] = $this->hash_password($data['password'], $user->salt);
                }
                else
                {
                    // unset password so it doesn't effect database entry if no password passed
                    unset($data['password']);
                }
            }
        }

        $this->events->trigger('extraWhere');
        $this->db->update($this->tables['users'], $data, array('id' => $user->id));

        if ($this->db->trans_status() === false)
        {
            $this->db->trans_rollback();

            $this->events->trigger(array('postUpdateUser', 'postUpdateUserUnsuccessful'));
            $this->setError('updateUnsuccessful');
            return false;
        }

        $this->db->trans_commit();

        $this->events->trigger(array('postUpdateUser', 'postUpdateUserSuccessful'));
        $this->setMessage('updateSuccessful');
        return true;
    }

    /**
     * delete_user
     *
     * @return bool
     **/
    public function delete()
    {
        $this->events->trigger('preDeleteUser');

        // remove user from groups
        $this->groups->clear();

        // delete user from users table should be placed after remove from group
        $affectedRows = $this->db->delete($this->tables['users'], array('id' => $id));

        if ($affectedRows == 0) return false;


        if ($this->db->trans_status() === false)
        {
            $this->db->trans_rollback();
            $this->events->trigger(array('postDeleteUser', 'postDeleteUserUnsuccessful'));
            $this->setError('deleteUnsuccessful');
            return false;
        }

//        $this->triggerEvents(array('postDeleteUser', 'postDeleteUserSuccessful'));
//        $this->setMessage('deleteSuccessful');
        return true;
    }

    /**
     * update_last_login
     *
     **/
    public function updateLastLogin()
    {
//        $this->triggerEvents('updateLastLogin');
//        $this->triggerEvents('extraWhere');

        $this->last_login = time();

        return $this->last_login;
    }

    public function setEmail($email)
    {
        if (\IonAuth\IonAuth\Helper\validateEmail($email)) $this->email = $email;
        else throw new \Exception('InvalidEmail');
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getGroups()
    {
        return $this->groups;
    }

    public function addGroup(Group $group)
    {
        $this->groups->add($group);
    }

    public function removeGroup(Group $group)
    {
        $this->groups->remove($group);
    }

    public function setFirstName($first_name)
    {
        $this->first_name = $first_name;
    }

    public function getFirstName()
    {
        return $this->first_name;
    }

    public function setLastName($last_name)
    {
        $this->last_name = $last_name;
    }

    public function getLastName()
    {
        return $this->last_name;
    }

    public function getFullName()
    {
        return $this->first_name . " " . $this->last_name;
    }

    public function getLastLogin()
    {
        return $this->last_login;
    }
}
