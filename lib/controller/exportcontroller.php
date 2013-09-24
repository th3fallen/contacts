<?php
/**
 * @author Thomas Tanghus
 * Copyright (c) 2013 Thomas Tanghus (thomas@tanghus.net)
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OCA\Contacts\Controller;

use OCA\Contacts\App,
	OCA\Contacts\JSONResponse,
	OCA\Contacts\Controller,
	OCA\Contacts\TextDownloadResponse,
	Sabre\VObject;

/**
 * Controller importing contacts
 */
class ExportController extends Controller {

	/**
	 * Export an entire address book.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function exportAddressBook() {
		\OCP\Util::writeLog('contacts', __METHOD__, \OCP\Util::DEBUG);
		$params = $this->request->urlParams;

		$addressBook = $this->app->getAddressBook($params['backend'], $params['addressbookid']);
		$lastModified = $addressBook->lastModified();

		$contacts = '';
		foreach($addressBook->getChildren() as $i => $contact) {
			$contacts .= $contact->serialize() . "\r\n";
		}
		$name = str_replace(' ', '_', $addressBook->getDisplayName()) . '.vcf';
		$response = new TextDownloadResponse($contacts, $name, 'text/directory');
		if(!is_null($lastModified)) {
			$response->addHeader('Cache-Control', 'private, must-revalidate');
			$response->setLastModified(\DateTime::createFromFormat('U', $lastModified) ?: null);
			$response->setETag(md5($lastModified));
		}

		return $response;
	}

	/**
	 * Export a single contact.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function exportContact() {

		$params = $this->request->urlParams;

		$addressBook = $this->app->getAddressBook($params['backend'], $params['addressbookid']);
		$contact = $addressBook->getChild($params['contactid']);

		if(!$contact) {
			$response = new JSONResponse();
			$response->bailOut(App::$l10n->t('Couldn\'t find contact.'));
			return $response;
		}

		$name = str_replace(' ', '_', $contact->getDisplayName()) . '.vcf';
		return new TextDownloadResponse($contact->serialize(), $name, 'text/vcard');
	}

	/**
	 * Export a selected range of contacts potentially from different backends and address books.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function exportSelected() {
		$contacts = $this->request['contacts'];

		// First sort the contacts by backend and address book.
		$targets = array();

		foreach($contacts as $contact) {
			if(!isset($targets[$contact['backend']])) {
				$targets[$contact['backend']] = array();
			}
			if(!isset($targets[$contact['backend']][$contact['addressbookid']])) {
				$targets[$contact['backend']][$contact['addressbookid']] = array();
			}
			$targets[$contact['backend']][$contact['addressbookid']][] = $contact['contactid'];
		}

		$exports = '';
		foreach($targets as $backend => $addressBooks) {
			foreach($addressBooks as $addressBookId => $contacts) {
				$addressBook = $this->app->getAddressBook($backend, $addressBookId);
				foreach($contacts as $contactId) {
					$contact = $addressBook->getChild($contactId);
					$exports .= $contact->serialize() . "\r\n";
				}
			}
		}

		$name = 'Selected_contacts' . '.vcf';
		return new TextDownloadResponse($exports, $name, 'text/vcard');
	}

}