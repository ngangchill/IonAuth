<?php

namespace IonAuth\IonAuth\Entities;

use IonAuth\IonAuth\Utilities\Collection\CollectionItem;

class Group implements CollectionItem
{
    private $id;


    /**
     * Function:  get_users_groups
     * ------------------------------
     * @param bool $id
     * @return array
     */


    public function getUsersGroups($id = false)
    {
        $this->events->trigger('getUsersGroup');

        //if no id was passed use the current users id
        $id || $id = $_SESSION['user_id'];

        return $this->db->select(
            'select ' . $this->tables['users_groups'] . '.' . $this->join['groups'] . ' as id, ' . $this->tables['groups'] . '.name, ' . $this->tables['groups'] . '.description
		     FROM ' . $this->tables['users_groups']
            . " LEFT JOIN " . $this->tables['groups'] . " ON " . $this->tables['users_groups'] . '.' . $this->join['groups'] . ' = ' . $this->tables['groups'] . '.id'
            . ' WHERE ' . $this->tables['users_groups'] . '.' . $this->join['users'] . " = " . $id
        );
    }

    /**
     * Function: add_to_group
     * -----------------------------
     * @param $groupId
     * @param bool $userId
     * @return bool
     */
    public function addToGroup($groupId, $userId = false)
    {
        $this->events->trigger('addToGroup');

        //if no id was passed use the current users id
        $userId || $userId = $this->session->userdata('userId');

        //check if unique - num_rows() > 0 means row found
        if (count(
            $this->db->where(array($this->join['groups'] => (int)$groupId, $this->join['users'] => (int)$userId))->get(
                $this->tables['usersGroups']
            )
        )
        ) {
            return false;
        }

        if ($return = $this->db->insert(
            $this->tables['usersGroups'],
            array($this->join['groups'] => (int)$groupId, $this->join['users'] => (int)$userId)
        )
        ) {
            if (isset($this->_cacheGroups[$groupId])) {
                $groupName = $this->_cacheGroups[$groupId];
            } else {
                $group = $this->group($groupId)->result();
                $groupName = $group[0]->name;
                $this->_cacheGroups[$groupId] = $groupName;
            }
            $this->_cacheUserInGroup[$userId][$groupId] = $groupName;
        }
        return $return;
    }

    /**
     * remove_from_group
     *
     * @return bool
     **/
    public function remove_from_group($group_ids = false, $user_id = false)
    {
        $this->events->trigger('removeFromGroup');
        // user id is required
        if (empty($userId)) {
            return false;
        }

        // if group id(s) are passed remove user from the group(s)
        if (!empty($groupIds)) {
            if (!is_array($groupIds)) {
                $groupIds = array($groupIds);
            }

            foreach ($groupIds as $groupId) {
                $this->db->delete(
                    $this->tables['usersGroups'],
                    array($this->join['groups'] => (int)$groupId, $this->join['users'] => (int)$user_id)
                );
                if (isset($this->_cacheUserInGroup[$userId]) && isset($this->_cacheUserInGroup[$userId][$groupId])) {
                    unset($this->_cacheUserInGroup[$userId][$groupId]);
                }
            }

            $return = true;
        } // otherwise remove user from all groups
        else {
            if ($return = $this->db->delete(
                $this->tables['usersGroups'],
                array($this->join['users'] => (int)$userId)
            )
            ) {
                $this->_cacheUserInGroup[$userId] = array();
            }
        }

        return $return;
    }

    /**
     * groups
     *
     * @return object
     **/
    public function groups()
    {
        $this->events->trigger('groups');

        //run each where that was passed
        if (isset($this->_ionWhere) && !empty($this->_ionWhere)) {
            foreach ($this->_ionWhere as $where) {
                $this->db->where($where);
            }
            $this->_ionWhere = array();
        }

        if (isset($this->_ionLimit) && isset($this->_ionOffset)) {
            $this->db->take($this->_ionLimit, $this->_ionOffset);

            $this->_ionLimit = null;
            $this->_ionOffset = null;
        } else {
            if (isset($this->_ionLimit)) {
                $this->db->take($this->_ionLimit);

                $this->_ionLimit = null;
            }
        }

        //set the order
        if (isset($this->_ionOrderBy) && isset($this->_ionOrder)) {
            $this->db->order_by($this->_ionOrderBy, $this->_ionOrder);
        }

        $this->response = $this->db->get($this->tables['groups']);

        return $this;
    }

    /**
     * group
     *
     * @return object
     **/
    public function group($id = null)
    {
        $this->events->trigger('group');

        if (isset($id)) {
            $this->db->where($this->tables['groups'] . '.id', $id);
        }

        $this->take(1);

        return $this->groups();
    }



    /**
     * create_group
     *
     * @param $groupName
     * @param $groupDescription
     * @param $additionalData
     * @return bool
     */
    public function createGroup($groupName = false, $groupDescription = '', $additionalData = array())
    {
        // bail if the group name was not passed
        if (!$groupName) {
            $this->setError('groupNameRequired');
            return false;
        }

        // bail if the group name already exists
        $existing_group = count($this->db->get_where($this->tables['groups'], array('name' => $groupName)));
        if ($existing_group !== 0) {
            $this->setError('groupAlreadyExists');
            return false;
        }

        $data = array(
            'name' => $groupName,
            'description' => $groupDescription
        );

        //filter out any data passed that doesnt have a matching column in the groups table
        //and merge the set group data and the additional data
        if (!empty($additionalData)) {
            $data = array_merge($this->_filterData($this->tables['groups'], $additionalData), $data);
        }

        $this->events->trigger('extraGroupSet');

        // insert the new group
        $this->db->insert($this->tables['groups'], $data);
        $groupId = $this->db->insert_id();

        // report success
        $this->setMessage('groupCreationSuccessful');

        // return the brand new group id
        return $groupId;
    }

    /**
     * update_group
     *
     * @param $groupId
     * @param
     * @return bool
     **/
    public function updateGroup($groupId = false, $groupName = false, $additionalData = array())
    {
        if (empty($groupId)) {
            return false;
        }

        $data = array();

        if (!empty($groupName)) {
            // we are changing the name, so do some checks

            // bail if the group name already exists
            $existingGroup = $this->db->get_where($this->tables['groups'], array('name' => $groupName))->first();
            if (isset($existingGroup->id) && $existingGroup->id != $groupId) {
                $this->setError('groupAlreadyExists');
                return false;
            }

            $data['name'] = $groupName;
        }


        // IMPORTANT!! Third parameter was string type $description; this following code is to maintain backward compatibility
        // New projects should work with 3rd param as array
        if (is_string($additionalData)) {
            $additionalData = array('description' => $additionalData);
        }


        //filter out any data passed that doesnt have a matching column in the groups table
        //and merge the set group data and the additional data
        if (!empty($additionalData)) {
            $data = array_merge($this->_filterData($this->tables['groups'], $additionalData), $data);
        }


        $this->db->update($this->tables['groups'], $data, array('id' => $groupId));

        $this->setMessage('groupUpdateSuccessful');

        return true;
    }

    /**
     * delete group
     *
     * @param $groupid, integer
     * @return bool
     **/
    public function deleteGroup($groupId = false)
    {
        // bail if mandatory param not set
        if (!$groupId || empty($groupId)) {
            return false;
        }

        $this->events->trigger('preDeleteGroup');

        $this->db->trans_begin();

        // remove all users from this group
        $this->db->delete($this->tables['usersGroups'], array($this->join['groups'] => $groupId));
        // remove the group itself
        $this->db->delete($this->tables['groups'], array('id' => $groupId));

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            $this->events->trigger(array('postDeleteGroup', 'postDeleteGroupUnsuccessful'));
            $this->setError('groupDeleteUnsuccessful');
            return false;
        }

        $this->db->trans_commit();
        $this->events->trigger(array('postDeleteGroup', 'postDeleteGroupUnsuccessful'));
        $this->setMessage('groupDeleteSuccessful');
        return true;
    }

    /**
     * getId
     *
     * @return $this->id
     */
    public function getId()
    {
        return $this->id;
    }
}
