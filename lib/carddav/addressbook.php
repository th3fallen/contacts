<?php
/**
 * ownCloud - Addressbook
 *
 * @author Thomas Tanghus
 * @copyright 2012-2014 Thomas Tanghus (thomas@tanghus.net)
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

namespace OCA\Contacts\CardDAV;

use OCA\Contacts;

/**
 * This class overrides __construct to get access to $addressBookInfo and
 * $carddavBackend, \Sabre\CardDAV\AddressBook::getACL() to return read/write
 * permissions based on user and shared state and it overrides
 * \Sabre\CardDAV\AddressBook::getChild() and \Sabre\CardDAV\AddressBook::getChildren()
 * to instantiate \OCA\Contacts\CardDAV\Cards.
*/
class AddressBook extends \Sabre\CardDAV\AddressBook {

	/**
	* CardDAV backend
	*
	* @var \Sabre\CardDAV\Backend\AbstractBackend
	*/
	protected $carddavBackend;

	/**
	* Constructor
	*
	* @param \Sabre\CardDAV\Backend\AbstractBackend $carddavBackend
	* @param array $addressBookInfo
	*/
	public function __construct(
		\Sabre\CardDAV\Backend\AbstractBackend $carddavBackend,
		array $addressBookInfo
	) {

		$this->carddavBackend = $carddavBackend;
		$this->addressBookInfo = $addressBookInfo;
		parent::__construct($carddavBackend, $addressBookInfo);

	}

	/**
	* Returns a list of ACE's for this node.
	*
	* Each ACE has the following properties:
	*   * 'privilege', a string such as {DAV:}read or {DAV:}write. These are
	*     currently the only supported privileges
	*   * 'principal', a url to the principal who owns the node
	*   * 'protected' (optional), indicating that this ACE is not allowed to
	*      be updated.
	*
	* @return array
	*/
	public function getACL() {

		$readprincipal = $this->getOwner();
		$writeprincipal = $this->getOwner();
		$createprincipal = $this->getOwner();
		$deleteprincipal = $this->getOwner();
		$uid = $this->carddavBackend->userIDByPrincipal($this->getOwner());

		$readWriteACL = array(
			array(
				'privilege' => '{DAV:}read',
				'principal' => 'principals/' . \OCP\User::getUser(),
				'protected' => true,
			),
			array(
				'privilege' => '{DAV:}write',
				'principal' => 'principals/' . \OCP\User::getUser(),
				'protected' => true,
			),
		);

		if($uid !== \OCP\User::getUser()) {
			list(, $id) = explode('::', $this->addressBookInfo['id']);
			$sharedAddressbook = \OCP\Share::getItemSharedWithBySource('addressbook', $id);
			if($sharedAddressbook) {
				if(($sharedAddressbook['permissions'] & \OCP\PERMISSION_CREATE)
					&& ($sharedAddressbook['permissions'] & \OCP\PERMISSION_UPDATE)
					&& ($sharedAddressbook['permissions'] & \OCP\PERMISSION_DELETE)
				) {
					return $readWriteACL;
				}
				if ($sharedAddressbook['permissions'] & \OCP\PERMISSION_CREATE) {
					$createprincipal = 'principals/' . \OCP\User::getUser();
				}
				if ($sharedAddressbook['permissions'] & \OCP\PERMISSION_READ) {
					$readprincipal = 'principals/' . \OCP\User::getUser();
				}
				if ($sharedAddressbook['permissions'] & \OCP\PERMISSION_UPDATE) {
					$writeprincipal = 'principals/' . \OCP\User::getUser();
				}
				if ($sharedAddressbook['permissions'] & \OCP\PERMISSION_DELETE) {
					$deleteprincipal = 'principals/' . \OCP\User::getUser();
				}
			}
		} else {
			return parent::getACL();
		}

		return array(
			array(
				'privilege' => '{DAV:}read',
				'principal' => $readprincipal,
				'protected' => true,
			),
			array(
				'privilege' => '{DAV:}write-content',
				'principal' => $writeprincipal,
				'protected' => true,
			),
			array(
				'privilege' => '{DAV:}bind',
				'principal' => $createprincipal,
				'protected' => true,
			),
			array(
				'privilege' => '{DAV:}unbind',
				'principal' => $deleteprincipal,
				'protected' => true,
			),
		);

	}

	public function getSupportedPrivilegeSet() {

		return array(
			'privilege'  => '{DAV:}all',
			'abstract'   => true,
			'aggregates' => array(
				array(
					'privilege'  => '{DAV:}read',
					'aggregates' => array(
						array(
							'privilege' => '{DAV:}read-acl',
							'abstract'  => true,
						),
						array(
							'privilege' => '{DAV:}read-current-user-privilege-set',
							'abstract'  => true,
						),
					),
				), // {DAV:}read
				array(
					'privilege'  => '{DAV:}write',
					'aggregates' => array(
						array(
							'privilege' => '{DAV:}write-acl',
							'abstract'  => true,
						),
						array(
							'privilege' => '{DAV:}write-properties',
							'abstract'  => true,
						),
						array(
							'privilege' => '{DAV:}write-content',
							'abstract'  => false,
						),
						array(
							'privilege' => '{DAV:}bind',
							'abstract'  => false,
						),
						array(
							'privilege' => '{DAV:}unbind',
							'abstract'  => false,
						),
						array(
							'privilege' => '{DAV:}unlock',
							'abstract'  => true,
						),
					),
				), // {DAV:}write
			),
		); // {DAV:}all

	}

	/**
	* Returns a card
	*
	* @param string $name
	* @return Card
	*/
	public function getChild($name) {

		$obj = $this->carddavBackend->getCard($this->addressBookInfo['id'],$name);
		if (!$obj) {
			throw new \Sabre\DAV\Exception\NotFound('Card not found');
		}
		return new Card($this->carddavBackend,$this->addressBookInfo,$obj);

	}

	/**
	* Returns the full list of cards
	*
	* @return array
	*/
	public function getChildren() {

		$objs = $this->carddavBackend->getCards($this->addressBookInfo['id']);
		$children = array();
		foreach($objs as $obj) {
			$children[] = new Card($this->carddavBackend,$this->addressBookInfo,$obj);
		}
		return $children;

	}

	/**
	* Returns the last modification date as a unix timestamp.
	*
	* @return int|null
	*/
	public function getLastModified() {

		return $this->carddavBackend->lastModifiedAddressBook($this->addressBookInfo['id']);

	}
}
