<?php

/**
 * ownCloud – LDAP group backend
 *
 * @author Arthur Schiwon
 * @copyright 2012 Arthur Schiwon blizzz@owncloud.com
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\user_ldap;

use OCA\user_ldap\lib\Access;
use OCA\user_ldap\lib\BackendUtility;

class GROUP_LDAP extends BackendUtility implements \OCP\GroupInterface {
	protected $enabled = false;

	public function __construct(Access $access) {
		parent::__construct($access);
		$filter = $this->access->connection->ldapGroupFilter;
		$gassoc = $this->access->connection->ldapGroupMemberAssocAttr;
		if(!empty($filter) && !empty($gassoc)) {
			$this->enabled = true;
		}
	}

	/**
	 * is user in group?
	 * @param string $uid uid of the user
	 * @param string $gid gid of the group
	 * @return bool
	 *
	 * Checks whether the user is member of a group or not.
	 */
	public function inGroup($uid, $gid) {
		if(!$this->enabled) {
			return false;
		}
		$cacheKey = 'inGroup'.$uid.':'.$gid;
		if($this->access->connection->isCached($cacheKey)) {
			return $this->access->connection->getFromCache($cacheKey);
		}

		$userDN = $this->access->username2dn($uid);
		$groupDN = $this->access->groupname2dn($gid);
		// just in case
		if(!$groupDN || !$userDN) {
			$this->access->connection->writeToCache($cacheKey, false);
			return false;
		}

		//check primary groups first
		$userPrimaryGroups = $this->getUserPrimaryGroups($userDN);
		if(in_array($gid, $userPrimaryGroups)) {
			$this->access->connection->writeToCache($cacheKey, true);
			return true;
		}

		//usually, LDAP attributes are said to be case insensitive. But there are exceptions of course.
		$members = array_keys($this->_groupMembers($groupDN));
		if(!$members) {
			$this->access->connection->writeToCache($cacheKey, false);
			return false;
		}

		//extra work if we don't get back user DNs
		//TODO: this can be done with one LDAP query
		if(strtolower($this->access->connection->ldapGroupMemberAssocAttr) === 'memberuid') {
			$dns = array();
			foreach($members as $mid) {
				$filter = str_replace('%uid', $mid, $this->access->connection->ldapLoginFilter);
				$ldap_users = $this->access->fetchListOfUsers($filter, 'dn');
				if(count($ldap_users) < 1) {
					continue;
				}
				$dns[] = $ldap_users[0];
			}
			$members = $dns;
		}

		$isInGroup = in_array($userDN, $members);
		$this->access->connection->writeToCache($cacheKey, $isInGroup);

		return $isInGroup;
	}

	/**
	 * @param string $dnGroup
	 * @param array|null &$seen
	 */
	private function _groupMembers($dnGroup, &$seen = null) {
		if ($seen === null) {
			$seen = array();
		}
		$allMembers = array();
		if (array_key_exists($dnGroup, $seen)) {
			// avoid loops
			return array();
		}
		// used extensively in cron job, caching makes sense for nested groups
		$cacheKey = '_groupMembers'.$dnGroup;
		if($this->access->connection->isCached($cacheKey)) {
			return $this->access->connection->getFromCache($cacheKey);
		}
		$seen[$dnGroup] = 1;
		$members = $this->access->readAttribute($dnGroup, $this->access->connection->ldapGroupMemberAssocAttr,
												$this->access->connection->ldapGroupFilter);
		if (is_array($members)) {
			foreach ($members as $memberDN) {
				$allMembers[$memberDN] = 1;
				$nestedGroups = $this->access->connection->ldapNestedGroups;
				if (!empty($nestedGroups)) {
					$subMembers = $this->_groupMembers($memberDN, $seen);
					if ($subMembers) {
						$allMembers = array_merge($allMembers, $subMembers);
					}
				}
			}
		}
		$this->access->connection->writeToCache($cacheKey, $allMembers);
		return $allMembers;
	}

	/**
	 * translates a primary group ID into an ownCloud internal name
	 * @param string $attr the attribute belonging to the group id
	 * @param string $gid
	 * @return string|bool
	 */
	public function primaryGroupID2Name($attr, $gid) {
		$cacheKey = 'primaryGroupIDtoName';
		if($this->access->connection->isCached($cacheKey)) {
			$groupNames = $this->access->connection->getFromCache($cacheKey);
			if(isset($groupNames[$gid])) {
				return $groupNames[$gid];
			}
		}

		//we need to get the DN from LDAP
		if($attr === 'primaryGroupID') {
			$searchAttr = 'primaryGroupToken';
		} else {
			//gidNumber is always the same
			$searchAttr = $attr;
		}
		$filter = $this->access->combineFilterWithAnd(array(
			$this->access->connection->ldapGroupFilter,
			$searchAttr . '=' . $gid
		));
		$result = $this->access->searchGroups($filter, $dn, 1);
		if(empty($result)) {
			return false;
		}
		$dn = $result[0];

		//and now the groupname
		//NOTE once we have seperate ownCloud group IDs and group names we can
		//directly read the display name attribute instead of the DN
		$name = $this->dn2groupname($dn);

		//cache and return
		if(!isset($groupNames)) {
			$groupNames = array();
		}
		$groupNames[$gid] = $name;
		$this->access->connection->writeToCache($cacheKey, $groupNames);
		return $name;
	}

	/**
	 * returns the entry's primary groups IDs per attribute
	 * @param string $dn
	 * @param string[] $attributes
	 * @return array having the pattern attribute => value
	 */
	private function getEntryGroupID($dn, $attributes) {
		$result = array();
		foreach($attributes as $attr) {
			$value = $this->access->readAttribute($dn, $attr);
			if(is_array($value) && !empty($value)) {
				$result[$attr] = $value;
			}
		}
		return $result;
	}

	/**
	 * returns the group's IDs per attribute
	 * @param string $dn
	 * @return array
	 */
	public function getGroupPrimaryGroupIDs($dn) {
		$gidAttributes = array('gidNumber', 'primaryGroupToken');
		return $this->getEntryGroupID($dn, $gidAttributes);
	}

	/**
	 * returns the users's primary group IDs per attribute
	 * @param string $dn
	 * @return array
	 */
	public function getUserPrimaryGroupIDs($dn) {
		$gidAttributes = array('gidNumber', 'primaryGroupID');
		return $this->getEntryGroupID($dn, $gidAttributes);
	}

	/**
	 * returns a list of users that have the given group as primary group
	 * @param string $groupDN
	 * @return string[]
	 */
	public function getUsersInPrimaryGroup($groupDN, $limit = -1, $offset = 0) {
		$groupIDs = $this->getGroupPrimaryGroupIDs($groupDN);
		if(empty($groupIDs)) {
			return array();
		}

		$filterParts = array();
		foreach($groupIDs as $attr => $gid) {
			if($attr === 'primaryGroupToken') {
				$searchAttr = 'primaryGroupID';
			} else {
				//gidNumber is always the same
				$searchAttr = $attr;
			}
			$filterParts[] = $searchAttr . '=' . $gid;
		}
		$filter = $this->access->combineFilterWithAnd(array(
			$this->access->connection->ldapUserFilter,
			$this->access->combineFilterWithOr($filterParts)
		));

		$users = $this->access->fetchListOfUsers(
			$filter,
			array($this->access->connection->ldapUserDisplayName, 'dn'),
			$limit,
			$offset
		);

		return $users;
	}

	/**
	 * gets the primary groups of a user
	 * @param string $dn
	 * @return string[]
	 */
	public function getUserPrimaryGroups($dn) {
		$groupIDs = $this->getUserPrimaryGroupIDs($dn);
		if(empty($groupIDs)) {
			return array();
		}

		$groups = array();
		foreach($groupIDs as $attr => $gid) {
			$groupName = $this->primaryGroupID2Name($attr, $gid);
			if($groupName !== false) {
				$groups[] = $groupName;
			}
		}

		return $groups;
	}

	/**
	 * Get all groups a user belongs to
	 * @param string $uid Name of the user
	 * @return array with group names
	 *
	 * This function fetches all groups a user belongs to. It does not check
	 * if the user exists at all.
	 */
	public function getUserGroups($uid) {
		if(!$this->enabled) {
			return array();
		}
		$cacheKey = 'getUserGroups'.$uid;
		if($this->access->connection->isCached($cacheKey)) {
			return $this->access->connection->getFromCache($cacheKey);
		}
		$userDN = $this->access->username2dn($uid);
		if(!$userDN) {
			$this->access->connection->writeToCache($cacheKey, array());
			return array();
		}

		//uniqueMember takes DN, memberuid the uid, so we need to distinguish
		if((strtolower($this->access->connection->ldapGroupMemberAssocAttr) === 'uniquemember')
			|| (strtolower($this->access->connection->ldapGroupMemberAssocAttr) === 'member')
		) {
			$uid = $userDN;
		} else if(strtolower($this->access->connection->ldapGroupMemberAssocAttr) === 'memberuid') {
			$result = $this->access->readAttribute($userDN, 'uid');
			$uid = $result[0];
		} else {
			// just in case
			$uid = $userDN;
		}

		$groups = array_values($this->getGroupsByMember($uid));
		$groups = array_merge($groups, $this->getUserPrimaryGroups($userDN));
		$groups = array_unique($this->access->ownCloudGroupNames($groups), SORT_LOCALE_STRING);

		$this->access->connection->writeToCache($cacheKey, $groups);

		return $groups;
	}

	/**
	 * @param string $dn
	 * @param array|null &$seen
	 */
	private function getGroupsByMember($dn, &$seen = null) {
		if ($seen === null) {
			$seen = array();
		}
		$allGroups = array();
		if (array_key_exists($dn, $seen)) {
			// avoid loops
			return array();
		}
		$seen[$dn] = true;
		$filter = $this->access->combineFilterWithAnd(array(
			$this->access->connection->ldapGroupFilter,
			$this->access->connection->ldapGroupMemberAssocAttr.'='.$dn
		));
		$groups = $this->access->fetchListOfGroups($filter,
			array($this->access->connection->ldapGroupDisplayName, 'dn'));
		if (is_array($groups)) {
			foreach ($groups as $groupobj) {
				$groupDN = $groupobj['dn'];
				$allGroups[$groupDN] = $groupobj;
				$nestedGroups = $this->access->connection->ldapNestedGroups;
				if (!empty($nestedGroups)) {
					$supergroups = $this->getGroupsByMember($groupDN, $seen);
					if (is_array($supergroups) && (count($supergroups)>0)) {
						$allGroups = array_merge($allGroups, $supergroups);
					}
				}
			}
		}
		return $allGroups;
	}

	/**
	 * get a list of all users in a group
	 * @return array with user ids
	 */
	public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0) {
		if(!$this->enabled) {
			return array();
		}
		if(!$this->groupExists($gid)) {
			return array();
		}
		$cachekey = 'usersInGroup-'.$gid.'-'.$search.'-'.$limit.'-'.$offset;
		// check for cache of the exact query
		$groupUsers = $this->access->connection->getFromCache($cachekey);
		if(!is_null($groupUsers)) {
			return $groupUsers;
		}

		// check for cache of the query without limit and offset
		$groupUsers = $this->access->connection->getFromCache('usersInGroup-'.$gid.'-'.$search);
		if(!is_null($groupUsers)) {
			$groupUsers = array_slice($groupUsers, $offset, $limit);
			$this->access->connection->writeToCache($cachekey, $groupUsers);
			return $groupUsers;
		}

		if($limit === -1) {
			$limit = null;
		}
		$groupDN = $this->access->groupname2dn($gid);
		if(!$groupDN) {
			// group couldn't be found, return empty resultset
			$this->access->connection->writeToCache($cachekey, array());
			return array();
		}

		$members = array_keys($this->_groupMembers($groupDN));
		if(!$members) {
			//in case users could not be retrieved, return empty resultset
			$this->access->connection->writeToCache($cachekey, array());
			return array();
		}

		$groupUsers = array();
		$isMemberUid = (strtolower($this->access->connection->ldapGroupMemberAssocAttr) === 'memberuid');
		foreach($members as $member) {
			if($isMemberUid) {
				//we got uids, need to get their DNs to 'tranlsate' them to usernames
				$filter = $this->access->combineFilterWithAnd(array(
					\OCP\Util::mb_str_replace('%uid', $member,
						$this->access->connection->ldapLoginFilter, 'UTF-8'),
					$this->access->getFilterPartForUserSearch($search)
				));
				$ldap_users = $this->access->fetchListOfUsers($filter, 'dn');
				if(count($ldap_users) < 1) {
					continue;
				}
				$groupUsers[] = $this->access->dn2username($ldap_users[0]);
			} else {
				//we got DNs, check if we need to filter by search or we can give back all of them
				if(!empty($search)) {
					if(!$this->access->readAttribute($member,
						$this->access->connection->ldapUserDisplayName,
						$this->access->getFilterPartForUserSearch($search))) {
						continue;
					}
				}
				// dn2username will also check if the users belong to the allowed base
				if($ocname = $this->access->dn2username($member)) {
					$groupUsers[] = $ocname;
				}
			}
		}

		natsort($groupUsers);
		$this->access->connection->writeToCache('usersInGroup-'.$gid.'-'.$search, $groupUsers);
		$groupUsers = array_slice($groupUsers, $offset, $limit);

		//and get users that have the group as primary
		$primaryUsers = $this->getUsersInPrimaryGroup($groupDN, $limit, $offset);
		$groupUsers = array_unique(array_merge($groupUsers, $primaryUsers));

		$this->access->connection->writeToCache($cachekey, $groupUsers);

		return $groupUsers;
	}

	/**
	 * returns the number of users in a group, who match the search term
	 * @param string $gid the internal group name
	 * @param string $search optional, a search string
	 * @return int|bool
	 */
	public function countUsersInGroup($gid, $search = '') {
		$cachekey = 'countUsersInGroup-'.$gid.'-'.$search;
		if(!$this->enabled || !$this->groupExists($gid)) {
			return false;
		}
		$groupUsers = $this->access->connection->getFromCache($cachekey);
		if(!is_null($groupUsers)) {
			return $groupUsers;
		}

		$groupDN = $this->access->groupname2dn($gid);
		if(!$groupDN) {
			// group couldn't be found, return empty resultset
			$this->access->connection->writeToCache($cachekey, false);
			return false;
		}

		$members = array_keys($this->_groupMembers($groupDN));
		if(!$members) {
			//in case users could not be retrieved, return empty resultset
			$this->access->connection->writeToCache($cachekey, false);
			return false;
		}

		if(empty($search)) {
			$groupUsers = count($members);
			$this->access->connection->writeToCache($cachekey, $groupUsers);
			return $groupUsers;
		}
		$isMemberUid =
			(strtolower($this->access->connection->ldapGroupMemberAssocAttr)
			=== 'memberuid');

		//we need to apply the search filter
		//alternatives that need to be checked:
		//a) get all users by search filter and array_intersect them
		//b) a, but only when less than 1k 10k ?k users like it is
		//c) put all DNs|uids in a LDAP filter, combine with the search string
		//   and let it count.
		//For now this is not important, because the only use of this method
		//does not supply a search string
		$groupUsers = array();
		foreach($members as $member) {
			if($isMemberUid) {
				//we got uids, need to get their DNs to 'tranlsate' them to usernames
				$filter = $this->access->combineFilterWithAnd(array(
					\OCP\Util::mb_str_replace('%uid', $member,
						$this->access->connection->ldapLoginFilter, 'UTF-8'),
					$this->access->getFilterPartForUserSearch($search)
				));
				$ldap_users = $this->access->fetchListOfUsers($filter, 'dn');
				if(count($ldap_users) < 1) {
					continue;
				}
				$groupUsers[] = $this->access->dn2username($ldap_users[0]);
			} else {
				//we need to apply the search filter now
				if(!$this->access->readAttribute($member,
					$this->access->connection->ldapUserDisplayName,
					$this->access->getFilterPartForUserSearch($search))) {
					continue;
				}
				// dn2username will also check if the users belong to the allowed base
				if($ocname = $this->access->dn2username($member)) {
					$groupUsers[] = $ocname;
				}
			}
		}

		//and get users that have the group as primary
		$primaryUsers = $this->getUsersInPrimaryGroup($groupDN);
		$groupUsers = array_unique(array_merge($groupUsers, $primaryUsers));

		return count($groupUsers);
	}

	/**
	 * get a list of all groups
	 * @return array with group names
	 *
	 * Returns a list with all groups (used by getGroups)
	 */
	protected function getGroupsChunk($search = '', $limit = -1, $offset = 0) {
		if(!$this->enabled) {
			return array();
		}
		$cachekey = 'getGroups-'.$search.'-'.$limit.'-'.$offset;

		//Check cache before driving unnecessary searches
		\OCP\Util::writeLog('user_ldap', 'getGroups '.$cachekey, \OCP\Util::DEBUG);
		$ldap_groups = $this->access->connection->getFromCache($cachekey);
		if(!is_null($ldap_groups)) {
			return $ldap_groups;
		}

		// if we'd pass -1 to LDAP search, we'd end up in a Protocol
		// error. With a limit of 0, we get 0 results. So we pass null.
		if($limit <= 0) {
			$limit = null;
		}
		$filter = $this->access->combineFilterWithAnd(array(
			$this->access->connection->ldapGroupFilter,
			$this->access->getFilterPartForGroupSearch($search)
		));
		\OCP\Util::writeLog('user_ldap', 'getGroups Filter '.$filter, \OCP\Util::DEBUG);
		$ldap_groups = $this->access->fetchListOfGroups($filter,
				array($this->access->connection->ldapGroupDisplayName, 'dn'),
				$limit,
				$offset);
		$ldap_groups = $this->access->ownCloudGroupNames($ldap_groups);

		$this->access->connection->writeToCache($cachekey, $ldap_groups);
		return $ldap_groups;
	}

	/**
	 * get a list of all groups using a paged search
	 * @return array with group names
	 *
	 * Returns a list with all groups
   	 * Uses a paged search if available to override a
   	 * server side search limit.
   	 * (active directory has a limit of 1000 by default)
	 */
	public function getGroups($search = '', $limit = -1, $offset = 0) {
		if(!$this->enabled) {
			return array();
		}
		$pagingsize = $this->access->connection->ldapPagingSize;
		if ((! $this->access->connection->hasPagedResultSupport)
		   	|| empty($pagingsize)) {
			$this->getGroupsChunk($search, $limit, $offset);
		}
		$maxGroups = 100000; // limit max results (just for safety reasons)
		if ($limit > -1) {
		   $overallLimit = min($limit, $maxGroups);
		} else {
		   $overallLimit = $maxGroups;
		}
		$chunkOffset = $offset;
		$allGroups = array();
		while ($chunkOffset < $overallLimit) {
			$chunkLimit = min($pagingsize, $overallLimit - $chunkOffset);
			$ldapGroups = $this->getGroupsChunk($search, $chunkLimit, $chunkOffset);
			$nread = count($ldapGroups);
			\OCP\Util::writeLog('user_ldap', 'getGroups('.$search.'): read '.$nread.' at offset '.$chunkOffset.' (limit: '.$chunkLimit.')', \OCP\Util::DEBUG);
			if ($nread) {
				$allGroups = array_merge($allGroups, $ldapGroups);
				$chunkOffset += $nread;
			}
			if ($nread < $chunkLimit) {
				break;
			}
		}
		return $allGroups;
	}

	/**
	 * @param string $group
	 */
	public function groupMatchesFilter($group) {
		return (strripos($group, $this->groupSearch) !== false);
	}

	/**
	 * check if a group exists
	 * @param string $gid
	 * @return bool
	 */
	public function groupExists($gid) {
		if($this->access->connection->isCached('groupExists'.$gid)) {
			return $this->access->connection->getFromCache('groupExists'.$gid);
		}

		//getting dn, if false the group does not exist. If dn, it may be mapped
		//only, requires more checking.
		$dn = $this->access->groupname2dn($gid);
		if(!$dn) {
			$this->access->connection->writeToCache('groupExists'.$gid, false);
			return false;
		}

		//if group really still exists, we will be able to read its objectclass
		if(!is_array($this->access->readAttribute($dn, ''))) {
			$this->access->connection->writeToCache('groupExists'.$gid, false);
			return false;
		}

		$this->access->connection->writeToCache('groupExists'.$gid, true);
		return true;
	}

	/**
	* Check if backend implements actions
	* @param int $actions bitwise-or'ed actions
	* @return boolean
	*
	* Returns the supported actions as int to be
	* compared with OC_USER_BACKEND_CREATE_USER etc.
	*/
	public function implementsActions($actions) {
		return (bool)(OC_GROUP_BACKEND_COUNT_USERS & $actions);
	}
}
