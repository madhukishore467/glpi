<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2009 by the INDEPNET Development Team.

 http://indepnet.net/   http://glpi-project.org
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GLPI.

 GLPI is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 GLPI is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 --------------------------------------------------------------------------
 */

// ----------------------------------------------------------------------
// Original Author of file:
// Purpose of file:
// ----------------------------------------------------------------------


if (!defined('GLPI_ROOT')){
	die("Sorry. You can't access directly to this file");
	}

// Tracking Classes

class Transfer extends CommonDBTM{

	/// Already transfer item
	var $already_transfer=array();	
	/// Items simulate to move - non recursive item or recursive item not visible in destination entity
	var $needtobe_transfer=array();	
	/// Items simulate to move - recursive item visible in destination entity
	var $noneedtobe_transfer=array();	
	/// Search in need to be transfer items
	var $item_search=array();
	/// Search in need to be exclude from transfer
	var $item_recurs=array();
	/// Options used to transfer
	var $options=array();
	/// Destination entity ID
	var $to=-1;
	/// type of initial item transfered
	var $inittype=0;
	/// item types which have infocoms
	var $INFOCOMS_TYPES = array(COMPUTER_TYPE, NETWORKING_TYPE, PRINTER_TYPE, MONITOR_TYPE, PERIPHERAL_TYPE, PHONE_TYPE, SOFTWARE_TYPE, SOFTWARELICENSE_TYPE);
	/// item types which have contracts
	var $CONTRACTS_TYPES = array(COMPUTER_TYPE, NETWORKING_TYPE, PRINTER_TYPE, MONITOR_TYPE, PERIPHERAL_TYPE, PHONE_TYPE, SOFTWARE_TYPE);
	/// item types which have tickets
	var $TICKETS_TYPES = array(COMPUTER_TYPE, NETWORKING_TYPE, PRINTER_TYPE, MONITOR_TYPE, PERIPHERAL_TYPE, PHONE_TYPE, SOFTWARE_TYPE);
	/// item types which have documents
	var $DOCUMENTS_TYPES=array(ENTERPRISE_TYPE, CONTRACT_TYPE, CONTACT_TYPE, CONSUMABLE_TYPE, CARTRIDGE_TYPE, COMPUTER_TYPE, NETWORKING_TYPE, PRINTER_TYPE, MONITOR_TYPE, PERIPHERAL_TYPE, PHONE_TYPE, SOFTWARE_TYPE, DOCUMENT_TYPE);


	/**
	 * Constructor
	**/
	function __construct(){
		$this->table="glpi_transfers";
		$this->type=TRANSFER_TYPE;
	}

	/**
	 * Transfer items
	 *
	 *@param $items items to transfer
	 *@param $to entity destination ID
	 *@param $options options used to transfer
	 *
	 **/
	function moveItems($items,$to,$options){
		global $CFG_GLPI;
		// unset mailing
		$CFG_GLPI["mailing"]=0;
		
		// $items=array(TYPE => array(id_items))
		// $options=array()
		
		$default_options=array(
			'keep_tickets'=>0,
			'keep_networklinks'=>0,
			'keep_reservations'=>0,
			'keep_history'=>0,
			'keep_devices'=>0,
			'keep_infocoms'=>0,

			'keep_dc_monitor'=>0,
			'clean_dc_monitor'=>0,
			'keep_dc_phone'=>0,
			'clean_dc_phone'=>0,
			'keep_dc_peripheral'=>0,
			'clean_dc_peripheral'=>0,
			'keep_dc_printer'=>0,
			'clean_dc_printer'=>0,

			'keep_enterprises'=>0,
			'clean_enterprises'=>0,
			'keep_contacts'=>0,
			'clean_contacts'=>0,

			'keep_contracts'=>0,
			'clean_contracts'=>0,

			'keep_softwares'=>0,
			'clean_softwares'=>0,

			'keep_documents'=>0,
			'clean_documents'=>0,
			
			'keep_cartridges_type' =>0,
			'clean_cartridges_type' =>0,
			'keep_cartridges' =>0,

			'keep_consumables' =>0,
		);
		$ci=new CommonItem();

		if ($to>=0){
			// Store to
			$this->to=$to;
			// Store options
			$this->options=$options;
			foreach ($default_options as $key => $val){
				if (!isset($this->options[$key])){
					$this->options[$key]=$val;
				}
			}
			
			// Simulate transfers To know which items need to be transfer
			$this->simulateTransfer($items);

			//printCleanArray($this->needtobe_transfer);

			// Software first (to avoid copy during computer transfer)
			$this->inittype=SOFTWARE_TYPE;
			if (isset($items[SOFTWARE_TYPE])&&count($items[SOFTWARE_TYPE])){
				foreach ($items[SOFTWARE_TYPE] as $ID){
					$this->transferItem(SOFTWARE_TYPE,$ID,$ID);
				}
			}
			
			// Computer before all other items
			$this->inittype=COMPUTER_TYPE;
			if (isset($items[COMPUTER_TYPE])&&count($items[COMPUTER_TYPE])){
				foreach ($items[COMPUTER_TYPE] as $ID){
					$this->transferItem(COMPUTER_TYPE,$ID,$ID);
				}
			}
			
			// Inventory Items : MONITOR....
			$INVENTORY_TYPES = array(NETWORKING_TYPE, PRINTER_TYPE, MONITOR_TYPE, PERIPHERAL_TYPE, PHONE_TYPE, SOFTWARELICENSE_TYPE, CARTRIDGE_TYPE, CONSUMABLE_TYPE);
			foreach ($INVENTORY_TYPES as $itemtype){
				$this->inittype=$itemtype;
				if (isset($items[$itemtype])&&count($items[$itemtype])){
					foreach ($items[$itemtype] as $ID){
						$this->transferItem($itemtype,$ID,$ID);
					}
				}
			}

			// Clean unused
			$this->cleanSoftwareVersions();

			// TODO : don't do this as ticket, history, ... will be lost
			// $this->cleanSoftwares();

			// Management Items
			$MANAGEMENT_TYPES = array(ENTERPRISE_TYPE, CONTRACT_TYPE, CONTACT_TYPE, DOCUMENT_TYPE);
			foreach ($MANAGEMENT_TYPES as $itemtype){
				$this->inittype=$itemtype;
				if (isset($items[$itemtype])&&count($items[$itemtype])){
					foreach ($items[$itemtype] as $ID){
						$this->transferItem($itemtype,$ID,$ID);
					}
				}
			}
			// Tickets
			$OTHER_TYPES = array(TRACKING_TYPE,GROUP_TYPE,LINK_TYPE);
			foreach ($OTHER_TYPES as $itemtype){
				$this->inittype=$itemtype;
				if (isset($items[$itemtype])&&count($items[$itemtype])){
					foreach ($items[$itemtype] as $ID){
						$this->transferItem($itemtype,$ID,$ID);
					}
				}
			}

		} // $to >= 0
	}
	
	/**
	* Add an item in the needtobe_transfer list
	*
	*@param $itemtype of the item
	*@param $ID of the item
	*
	**/
	function addToBeTransfer ($itemtype, $ID) {
		global $LINK_ID_TABLE;
		// error_log("Transfer::addToBeTransfer(".$LINK_ID_TABLE[$itemtype].",$ID)");
		
		if (!isset($this->needtobe_transfer[$itemtype])){
			$this->needtobe_transfer[$itemtype]=array();
		}
		// Can't be in both list (in fact, always false)
		if (isset($this->noneedtobe_transfer[$itemtype][$ID]))
			unset($this->noneedtobe_transfer[$itemtype][$ID]);
			
		$this->needtobe_transfer[$itemtype][$ID]=$ID;
	}

	/**
	* Add an item in the noneedtobe_transfer list
	*
	*@param $itemtype of the item
	*@param $ID of the item
	*
	**/
	function addNotToBeTransfer ($itemtype, $ID) {
		global $LINK_ID_TABLE;
		// error_log("Transfer::addNotToBeTransfer(".$LINK_ID_TABLE[$itemtype].",$ID)");
		
		if (!isset($this->noneedtobe_transfer[$itemtype])){
			$this->noneedtobe_transfer[$itemtype]=array();
		}
		// Can't be in both list (in fact, always true)
		if (!isset($this->needtobe_transfer[$itemtype][$ID])) {
			$this->noneedtobe_transfer[$itemtype][$ID]=$ID;
		}
	}

	/**
	* simulate the transfer to know which items need to be transfer
	*
	*@param $items Array of the items to transfer
	*
	**/
	function simulateTransfer($items){
		global $DB,$LINK_ID_TABLE,$CFG_GLPI;

		// Init types :
		$types=array(COMPUTER_TYPE, NETWORKING_TYPE, PRINTER_TYPE, MONITOR_TYPE, PERIPHERAL_TYPE, PHONE_TYPE,
			SOFTWARE_TYPE, SOFTWARELICENSE_TYPE, SOFTWAREVERSION_TYPE, CONTRACT_TYPE, ENTERPRISE_TYPE, CONTACT_TYPE,
			TRACKING_TYPE, DOCUMENT_TYPE, CARTRIDGE_TYPE, CONSUMABLE_TYPE, LINK_TYPE);
		
		foreach ($types as $t){
			if (!isset($this->needtobe_transfer[$t])){
					$this->needtobe_transfer[$t]=array();
			}
		}
		foreach ($CFG_GLPI["recursive_type"] as $t => $table) {
			if (!isset($this->noneedtobe_transfer[$t])){
					$this->noneedtobe_transfer[$t]=array();
			}
		}
      // not recursive but need this
		$this->noneedtobe_transfer[SOFTWAREVERSION_TYPE]=array(); 


      $to_entity_ancestors = getAncestorsOf("glpi_entities",$this->to);
            
      // Copy items to needtobe_transfer
		foreach ($items as $key => $tab){
			if (count($tab)){
				foreach ($tab as $ID){
					$this->addToBeTransfer($key,$ID);
				}
			}
		}

		// Computer first
		$this->item_search[COMPUTER_TYPE]=$this->createSearchConditionUsingArray($this->needtobe_transfer[COMPUTER_TYPE]);

		// DIRECT CONNECTIONS

		$DC_CONNECT=array();
		if ($this->options['keep_dc_monitor']){
			$DC_CONNECT[]=MONITOR_TYPE;
		}
		if ($this->options['keep_dc_phone']){
			$DC_CONNECT[]=PHONE_TYPE;
		}
		if ($this->options['keep_dc_peripheral']){
			$DC_CONNECT[]=PERIPHERAL_TYPE;
		}
		if ($this->options['keep_dc_printer']){
			$DC_CONNECT[]=PRINTER_TYPE;
		}
		$ci = new CommonItem();
		if (count($DC_CONNECT)&&count($this->needtobe_transfer[COMPUTER_TYPE])>0){
			foreach ($DC_CONNECT as $itemtype){
				// Clean DB / Search unexisting links and force disconnect
				$query="SELECT glpi_computers_items.ID 
					FROM glpi_computers_items 
					LEFT JOIN ".$LINK_ID_TABLE[$itemtype]." ON (glpi_computers_items.items_id = ".$LINK_ID_TABLE[$itemtype].".ID )
					WHERE glpi_computers_items.itemtype='".$itemtype."' AND ".$LINK_ID_TABLE[$itemtype].".ID IS NULL";

				if ($result = $DB->query($query)) {
					if ($DB->numrows($result)>0) { 
						while ($data=$DB->fetch_array($result)){
							Disconnect($data['ID'],0,false);
						}
					}
				}
				

				$query = "SELECT DISTINCT items_id
				FROM glpi_computers_items 
				WHERE itemtype='".$itemtype."' AND computers_id IN ".$this->item_search[COMPUTER_TYPE];
				if ($result = $DB->query($query)) {
					if ($DB->numrows($result)>0) { 
						while ($data=$DB->fetch_array($result)){
							if (isset($CFG_GLPI["recursive_type"][$itemtype])
                           && $ci->getFromDB($itemtype,$data['items_id'])
                           && $ci->obj->isRecursive()
                           && in_array($ci->obj->getEntityID(), $to_entity_ancestors)) {
								$this->addNotToBeTransfer($itemtype,$data['items_id']);
							} else {
								$this->addToBeTransfer($itemtype,$data['items_id']);
							}
						}
					}
				}
			}
			$this->item_search[$itemtype]=$this->createSearchConditionUsingArray($this->needtobe_transfer[$itemtype]);
			if (isset($CFG_GLPI["recursive_type"][$itemtype])) {
				$this->item_recurs[$itemtype]=$this->createSearchConditionUsingArray($this->noneedtobe_transfer[$itemtype]);
			}
		} // End of direct connections	

		// Licence / Software :  keep / delete + clean unused / keep unused 
		if ($this->options['keep_softwares']){
			// Clean DB
			$query="SELECT glpi_computers_softwaresversions.ID 
				FROM glpi_computers_softwaresversions 
				LEFT JOIN glpi_computers ON (glpi_computers_softwaresversions.cID = glpi_computers.ID ) 
				WHERE glpi_computers.ID IS NULL";

			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					while ($data=$DB->fetch_array($result)){
						$query="DELETE FROM glpi_computers_softwaresversions WHERE ID='".$data['ID']."'";
						$DB->query($query);
					}
				}
			}

			// Clean DB
			$query="SELECT glpi_computers_softwaresversions.ID 
				FROM glpi_computers_softwaresversions 
				LEFT JOIN glpi_softwaresversions ON (glpi_computers_softwaresversions.vID = glpi_softwaresversions.ID ) 
				WHERE glpi_softwaresversions.ID IS NULL";

			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					while ($data=$DB->fetch_array($result)){
						$query="DELETE FROM glpi_computers_softwaresversions WHERE ID='".$data['ID']."'";
						$DB->query($query);
					}
				}
			}

			// Clean DB
			$query="SELECT glpi_softwaresversions.ID 
				FROM glpi_softwaresversions 
				LEFT JOIN glpi_softwares ON (glpi_softwares.ID = glpi_softwaresversions.sID ) 
				WHERE glpi_softwares.ID IS NULL";

			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					while ($data=$DB->fetch_array($result)){
						$query="DELETE FROM glpi_softwaresversions WHERE ID='".$data['ID']."'";
						$DB->query($query);
					}
				}
			}

			$query = "SELECT glpi_softwares.ID, glpi_softwares.entities_id, glpi_softwares.recursive, glpi_softwaresversions.ID AS vID
				FROM glpi_computers_softwaresversions 
				INNER JOIN glpi_softwaresversions ON (glpi_computers_softwaresversions.vID = glpi_softwaresversions.ID)
				INNER JOIN glpi_softwares ON (glpi_softwares.ID = glpi_softwaresversions.sID)
				WHERE glpi_computers_softwaresversions.cID IN ".$this->item_search[COMPUTER_TYPE];
			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					while ($data=$DB->fetch_array($result)){
                  if ($data['recursive'] && in_array($data['entities_id'], $to_entity_ancestors)) {
							$this->addNotToBeTransfer(SOFTWAREVERSION_TYPE,$data['vID']);
						} else {
							$this->addToBeTransfer(SOFTWAREVERSION_TYPE,$data['vID']);
						}
					}
				}
			}

			if (count($this->needtobe_transfer[COMPUTER_TYPE])>0) { // because -1 (empty list) is possible for computers_id
				// Transfer affected license (always even if recursive)
				$query = "SELECT ID FROM glpi_softwareslicenses WHERE computers_id IN ".$this->item_search[COMPUTER_TYPE];
				foreach ($DB->request($query) AS $lic) {
					$this->addToBeTransfer(SOFTWARELICENSE_TYPE,$lic['ID']);			
				}
			}
		}

		// Software: From user choice only
		$this->item_search[SOFTWARE_TYPE]=$this->createSearchConditionUsingArray($this->needtobe_transfer[SOFTWARE_TYPE]);
		$this->item_recurs[SOFTWARE_TYPE]=$this->createSearchConditionUsingArray($this->noneedtobe_transfer[SOFTWARE_TYPE]);

		// Move license of software 
		// TODO : should we transfert "affected license" ? 
		$query = "SELECT ID, buy_version, use_version FROM glpi_softwareslicenses WHERE sID IN ".$this->item_search[SOFTWARE_TYPE];
		foreach ($DB->request($query) AS $lic) {
			$this->addToBeTransfer(SOFTWARELICENSE_TYPE,$lic['ID']);			

			// Force version transfer (remove from item_recurs)
			if ($lic['buy_version']>0) {
				$this->addToBeTransfer(SOFTWAREVERSION_TYPE,$lic['buy_version']);	
			}
			if ($lic['use_version']>0) {
				$this->addToBeTransfer(SOFTWAREVERSION_TYPE,$lic['use_version']);
			}
		}

		// Licenses: from softwares  and computers (affected)
		$this->item_search[SOFTWARELICENSE_TYPE]=$this->createSearchConditionUsingArray($this->needtobe_transfer[SOFTWARELICENSE_TYPE]);
		$this->item_recurs[SOFTWARELICENSE_TYPE]=$this->createSearchConditionUsingArray($this->noneedtobe_transfer[SOFTWARELICENSE_TYPE]);
		
		// Versions: from affected licenses and installed versions
		$this->item_search[SOFTWAREVERSION_TYPE]=$this->createSearchConditionUsingArray($this->needtobe_transfer[SOFTWAREVERSION_TYPE]);
		$this->item_recurs[SOFTWAREVERSION_TYPE]=$this->createSearchConditionUsingArray($this->noneedtobe_transfer[SOFTWAREVERSION_TYPE]);

		$this->item_search[NETWORKING_TYPE]=$this->createSearchConditionUsingArray($this->needtobe_transfer[NETWORKING_TYPE]);

		// Tickets
		if ($this->options['keep_tickets']){
			foreach ($this->TICKETS_TYPES as $itemtype)
			if(isset($this->item_search[$itemtype])){
				$query="SELECT DISTINCT ID FROM glpi_tickets
				WHERE itemtype='$itemtype' AND items_id IN ".$this->item_search[$itemtype];
				if ($result = $DB->query($query)) {
					if ($DB->numrows($result)>0) { 
						while ($data=$DB->fetch_array($result)){
							$this->addToBeTransfer(TRACKING_TYPE,$data['ID']);
						}
					}
				}
			}
		}	
		$this->item_search[TRACKING_TYPE]=$this->createSearchConditionUsingArray($this->needtobe_transfer[TRACKING_TYPE]);

		// Contract : keep / delete + clean unused / keep unused
		if ($this->options['keep_contracts']){
			foreach ($this->CONTRACTS_TYPES as $itemtype)
			if (isset($this->item_search[$itemtype])){
				// Clean DB
				$query="SELECT glpi_contracts_items.ID FROM glpi_contracts_items 
					LEFT JOIN ".$LINK_ID_TABLE[$itemtype]." ON (glpi_contracts_items.items_id = ".$LINK_ID_TABLE[$itemtype].".ID )
					WHERE glpi_contracts_items.itemtype='".$itemtype."' AND ".$LINK_ID_TABLE[$itemtype].".ID IS NULL";

				if ($result = $DB->query($query)) {
					if ($DB->numrows($result)>0) { 
						while ($data=$DB->fetch_array($result)){
							$query="DELETE FROM glpi_contracts_items WHERE ID='".$data['ID']."'";
							$DB->query($query);
						}
					}
				}

				$query="SELECT contracts_id, glpi_contracts.entities_id, glpi_contracts.recursive" .
						" FROM glpi_contracts_items" .
						" LEFT JOIN glpi_contracts ON (glpi_contracts_items.contracts_id=glpi_contracts.ID)" .
						" WHERE glpi_contracts_items.itemtype='$itemtype'
                        AND glpi_contracts_items.items_id IN ".$this->item_search[$itemtype];
				if ($result = $DB->query($query)) {
					if ($DB->numrows($result)>0) { 
						while ($data=$DB->fetch_array($result)){
                     if ($data['recursive'] && in_array($data['entities_id'], $to_entity_ancestors)) {
								$this->addNotToBeTransfer(CONTRACT_TYPE,$data['contracts_id']);
							} else {
								$this->addToBeTransfer(CONTRACT_TYPE,$data['contracts_id']);
							}
						}
					}
				}
			}
		}
		$this->item_search[CONTRACT_TYPE]=$this->createSearchConditionUsingArray($this->needtobe_transfer[CONTRACT_TYPE]);
		$this->item_recurs[CONTRACT_TYPE]=$this->createSearchConditionUsingArray($this->noneedtobe_transfer[CONTRACT_TYPE]);
		// Enterprise (depending of item link) / Contract - infocoms : keep / delete + clean unused / keep unused
		
		if ($this->options['keep_enterprises']){
			// Clean DB
			$query="SELECT glpi_contracts_suppliers.ID FROM glpi_contracts_suppliers 
				LEFT JOIN glpi_contracts ON (glpi_contracts_suppliers.contracts_id = glpi_contracts.ID ) 
				WHERE glpi_contracts.ID IS NULL";

			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					while ($data=$DB->fetch_array($result)){
						$query="DELETE FROM glpi_contracts_suppliers WHERE ID='".$data['ID']."'";
						$DB->query($query);
					}
				}
			}

			// Clean DB
			$query="SELECT glpi_contracts_suppliers.ID FROM glpi_contracts_suppliers 
				LEFT JOIN glpi_suppliers ON (glpi_contracts_suppliers.suppliers_id = glpi_suppliers.ID ) 
				WHERE glpi_suppliers.ID IS NULL";

			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					while ($data=$DB->fetch_array($result)){
						$query="DELETE FROM glpi_contracts_suppliers WHERE ID='".$data['ID']."'";
						$DB->query($query);
					}
				}
			}

			// Enterprise Contract
			$query="SELECT DISTINCT suppliers_id, glpi_suppliers.recursive, glpi_suppliers.entities_id" .
					" FROM glpi_contracts_suppliers " .
					" LEFT JOIN glpi_suppliers ON (glpi_suppliers.ID=glpi_contracts_suppliers.suppliers_id) " .
					" WHERE contracts_id IN ".$this->item_search[CONTRACT_TYPE];
			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					while ($data=$DB->fetch_array($result)){
                  if ($data['recursive'] && in_array($data['entities_id'], $to_entity_ancestors)) {
							$this->addNotToBeTransfer(ENTERPRISE_TYPE,$data['suppliers_id']);
						} else {
							$this->addToBeTransfer(ENTERPRISE_TYPE,$data['suppliers_id']);
						}
					}
				}
			}
			// Ticket Enterprise
			$query="SELECT DISTINCT suppliers_id_assign, glpi_suppliers.recursive, glpi_suppliers.entities_id" .
					" FROM glpi_tickets" .
					" LEFT JOIN glpi_suppliers ON (glpi_suppliers.ID=glpi_tickets.suppliers_id_assign) " .
					" WHERE suppliers_id_assign > 0 AND glpi_tickets.ID IN ".$this->item_search[TRACKING_TYPE];
			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					while ($data=$DB->fetch_array($result)){
                  if ($data['recursive'] && in_array($data['entities_id'], $to_entity_ancestors)) {
							$this->addNotToBeTransfer(ENTERPRISE_TYPE,$data['suppliers_id_assign']);
						} else {
							$this->addToBeTransfer(ENTERPRISE_TYPE,$data['suppliers_id_assign']);
						}
					}
				}
			}



			// Enterprise infocoms
			if ($this->options['keep_infocoms']){
				foreach ($this->INFOCOMS_TYPES as $itemtype){
					if (isset($this->item_search[$itemtype])){
						// Clean DB
						$query="SELECT glpi_infocoms.ID FROM glpi_infocoms 
							LEFT JOIN ".$LINK_ID_TABLE[$itemtype]." ON (glpi_infocoms.items_id = ".$LINK_ID_TABLE[$itemtype].".ID )
							WHERE glpi_infocoms.itemtype='".$itemtype."' AND ".$LINK_ID_TABLE[$itemtype].".ID IS NULL";
		
						if ($result = $DB->query($query)) {
							if ($DB->numrows($result)>0) { 
								while ($data=$DB->fetch_array($result)){
									$query="DELETE FROM glpi_infocoms WHERE ID='".$data['ID']."'";
									$DB->query($query);
								}
							}
						}
	
						$query="SELECT DISTINCT suppliers_id, glpi_suppliers.recursive, glpi_suppliers.entities_id" .
								" FROM glpi_infocoms" .
								" LEFT JOIN glpi_suppliers ON (glpi_suppliers.ID=glpi_infocoms.suppliers_id) " .
								" WHERE suppliers_id > 0 AND itemtype='$itemtype' AND items_id IN ".$this->item_search[$itemtype];
						if ($result = $DB->query($query)) {
							if ($DB->numrows($result)>0) { 
								while ($data=$DB->fetch_array($result)){
                           if ($data['recursive'] && in_array($data['entities_id'], $to_entity_ancestors)) {
										$this->addNotToBeTransfer(ENTERPRISE_TYPE,$data['suppliers_id']);
									} else {
										$this->addToBeTransfer(ENTERPRISE_TYPE,$data['suppliers_id']);
									}
								}
							}
						}
					}
				}
			}
		}
		$this->item_search[ENTERPRISE_TYPE]=$this->createSearchConditionUsingArray($this->needtobe_transfer[ENTERPRISE_TYPE]);
		$this->item_recurs[ENTERPRISE_TYPE]=$this->createSearchConditionUsingArray($this->noneedtobe_transfer[ENTERPRISE_TYPE]);

		// Contact / Enterprise : keep / delete + clean unused / keep unused
		if ($this->options['keep_contacts']){
			// Clean DB
			$query="SELECT glpi_contacts_suppliers.ID FROM glpi_contacts_suppliers 
				LEFT JOIN glpi_contacts ON (glpi_contacts_suppliers.contacts_id = glpi_contacts.ID ) 
				WHERE glpi_contacts.ID IS NULL";

			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					while ($data=$DB->fetch_array($result)){
						$query="DELETE FROM glpi_contacts_suppliers WHERE ID='".$data['ID']."'";
						$DB->query($query);
					}
				}
			}
			// Clean DB
			$query="SELECT glpi_contacts_suppliers.ID FROM glpi_contacts_suppliers 
				LEFT JOIN glpi_suppliers ON (glpi_contacts_suppliers.suppliers_id = glpi_suppliers.ID ) 
				WHERE glpi_suppliers.ID IS NULL";

			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					while ($data=$DB->fetch_array($result)){
						$query="DELETE FROM glpi_contacts_suppliers WHERE ID='".$data['ID']."'";
						$DB->query($query);
					}
				}
			}


			// Enterprise Contact
			$query="SELECT DISTINCT contacts_id, glpi_contacts.recursive, glpi_contacts.entities_id " .
					" FROM glpi_contacts_suppliers" .
					" LEFT JOIN glpi_contacts ON (glpi_contacts.ID=glpi_contacts_suppliers.contacts_id) " .
					" WHERE suppliers_id IN ".$this->item_search[ENTERPRISE_TYPE];
			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					while ($data=$DB->fetch_array($result)){
                  if ($data['recursive'] && in_array($data['entities_id'], $to_entity_ancestors)) {
							$this->addNotToBeTransfer(CONTACT_TYPE,$data['contacts_id']);
						} else {
							$this->addToBeTransfer(CONTACT_TYPE,$data['contacts_id']);
						}
					}
				}
			}
		}
		$this->item_search[CONTACT_TYPE]=$this->createSearchConditionUsingArray($this->needtobe_transfer[CONTACT_TYPE]);
		$this->item_recurs[CONTACT_TYPE]=$this->createSearchConditionUsingArray($this->noneedtobe_transfer[CONTACT_TYPE]);

		// Document : keep / delete + clean unused / keep unused
		if ($this->options['keep_documents']){
			foreach ($this->DOCUMENTS_TYPES as $itemtype)
			if (isset($this->item_search[$itemtype])){
				// Clean DB
				$query="SELECT glpi_documents_items.ID FROM glpi_documents_items 
					LEFT JOIN ".$LINK_ID_TABLE[$itemtype]." ON (glpi_documents_items.items_id = ".$LINK_ID_TABLE[$itemtype].".ID )
					WHERE glpi_documents_items.itemtype='".$itemtype."' AND ".$LINK_ID_TABLE[$itemtype].".ID IS NULL";
		
				if ($result = $DB->query($query)) {
					if ($DB->numrows($result)>0) { 
						while ($data=$DB->fetch_array($result)){
							$query="DELETE FROM glpi_documents_items WHERE ID='".$data['ID']."'";
							$DB->query($query);
						}
					}
				}

				$query="SELECT documents_id, glpi_documents.recursive, glpi_documents.entities_id" .
						" FROM glpi_documents_items" .
						" LEFT JOIN glpi_documents ON (glpi_documents.ID=glpi_documents_items.documents_id) " .
						" WHERE itemtype='$itemtype' AND items_id IN ".$this->item_search[$itemtype];
				if ($result = $DB->query($query)) {
					if ($DB->numrows($result)>0) { 
						while ($data=$DB->fetch_array($result)){
                     if ($data['recursive'] && in_array($data['entities_id'], $to_entity_ancestors)) {
								$this->addNotToBeTransfer(DOCUMENT_TYPE,$data['documents_id']);
							} else {
								$this->addToBeTransfer(DOCUMENT_TYPE,$data['documents_id']);
							}
						}
					}
				}
			}
		}
		$this->item_search[DOCUMENT_TYPE]=$this->createSearchConditionUsingArray($this->needtobe_transfer[DOCUMENT_TYPE]);
		$this->item_recurs[DOCUMENT_TYPE]=$this->createSearchConditionUsingArray($this->noneedtobe_transfer[DOCUMENT_TYPE]);

		// printer -> cartridges : keep / delete + clean
		if ($this->options['keep_cartridges_type']){
			if (isset($this->item_search[PRINTER_TYPE])){
				$query="SELECT cartridgesitems_id FROM glpi_cartridges
				WHERE printers_id IN ".$this->item_search[PRINTER_TYPE];
				if ($result = $DB->query($query)) {
					if ($DB->numrows($result)>0) { 
						while ($data=$DB->fetch_array($result)){
							$this->addToBeTransfer(CARTRIDGE_TYPE,$data['cartridgesitems_id']);
						}
					}
				}
			}
		}
		$this->item_search[CARTRIDGE_TYPE]=$this->createSearchConditionUsingArray($this->needtobe_transfer[CARTRIDGE_TYPE]);


		// Init all item_search if not defined
		foreach ($types as $itemtype){
			if (!isset($this->item_search[$itemtype])){
				$this->item_search[$itemtype]="(-1)";
			}
		}

	}

	/**
	* Create IN condition for SQL requests based on a array if ID
	*
	*@param $array array of ID
	*@return string of the IN condition
	**/
	function createSearchConditionUsingArray($array){
		if (is_array($array)&&count($array)){
			return "('".implode("','",$array)."')";
		} else {
			return "(-1)";
		}
	}

	/**
	* transfer an item to another item (may be the same) in the new entity
	*
	*@param $itemtype item type to transfer
	*@param $ID ID of the item to transfer
	*@param $newID new ID of the ite
	*
	* Transfer item to a new Item if $ID==$newID : only update entities_id field : $ID!=$new ID -> copy datas (like template system)
	*@return nothing (diplays)
	*
	**/
	function transferItem($itemtype,$ID,$newID){
		global $CFG_GLPI,$DB;
		
		$cinew=new CommonItem();
		// Is already transfer ?
		if (!isset($this->already_transfer[$itemtype][$ID])){
			// Check computer exists ?
			if ($cinew->getFromDB($itemtype,$newID)){

				// Manage Ocs links 
				$dataocslink=array();
				$ocs_computer=false;
				if ($itemtype==COMPUTER_TYPE && $CFG_GLPI['ocs_mode']){
					$query="SELECT * FROM glpi_ocslinks WHERE glpi_id='$ID'";
					if ($result=$DB->query($query)){
						if ($DB->numrows($result)>0){
							$dataocslink=$DB->fetch_assoc($result);
							$ocs_computer=true;
						}
					}
					
				}

				// Network connection ? keep connected / keep_disconnected / delete
				if (in_array($itemtype,
					array(COMPUTER_TYPE,NETWORKING_TYPE,PRINTER_TYPE,MONITOR_TYPE,PERIPHERAL_TYPE,PHONE_TYPE))) {
					$this->transferNetworkLink($itemtype,$ID,$newID,$ocs_computer);
				}
				// Device : keep / delete : network case : delete if net connection delete in ocs case
				if (in_array($itemtype,array(COMPUTER_TYPE))){
					$this->transferDevices($itemtype,$ID,$ocs_computer);
				}
				// Reservation : keep / delete
				if (in_array($itemtype,$CFG_GLPI["reservation_types"])){
					$this->transferReservations($itemtype,$ID,$newID);
				}
				// History : keep / delete
				$this->transferHistory($itemtype,$ID,$newID);
				// Ticket : delete / keep and clean ref / keep and move
				$this->transferTickets($itemtype,$ID,$newID);
				// Infocoms : keep / delete
				if (in_array($itemtype,$this->INFOCOMS_TYPES)) {
					$this->transferInfocoms($itemtype,$ID,$newID);
				}
			
				if ($itemtype==SOFTWARE_TYPE){
					$this->transferSoftwareLicensesAndVersions($ID);
				}
				if ($itemtype==COMPUTER_TYPE){
					// Monitor Direct Connect : keep / delete + clean unused / keep unused 
					$this->transferDirectConnection($itemtype,$ID,MONITOR_TYPE,$ocs_computer);
					// Peripheral Direct Connect : keep / delete + clean unused / keep unused 
					$this->transferDirectConnection($itemtype,$ID,PERIPHERAL_TYPE,$ocs_computer);
					// Phone Direct Connect : keep / delete + clean unused / keep unused 
					$this->transferDirectConnection($itemtype,$ID,PHONE_TYPE);
					// Printer Direct Connect : keep / delete + clean unused / keep unused 
					$this->transferDirectConnection($itemtype,$ID,PRINTER_TYPE,$ocs_computer);
					// Licence / Software :  keep / delete + clean unused / keep unused 
					$this->transferComputerSoftwares($itemtype,$ocs_computer);
				}
				if ($itemtype==SOFTWARELICENSE_TYPE){
					$this->transferLicenseSoftwares($ID);
				}
				// Computer Direct Connect : delete link if it is the initial transfer item (no recursion)
				if ($this->inittype==$itemtype&&in_array($itemtype,
					array(PRINTER_TYPE,MONITOR_TYPE,PERIPHERAL_TYPE,PHONE_TYPE))){
					$this->deleteDirectConnection($itemtype,$ID);
				}

				// Contract : keep / delete + clean unused / keep unused
				if (in_array($itemtype,$this->CONTRACTS_TYPES)) {
					$this->transferContracts($itemtype,$ID,$newID);
				}

				// Contact / Enterprise : keep / delete + clean unused / keep unused
				if ($itemtype==ENTERPRISE_TYPE){
					$this->transferEnterpriseContacts($ID,$newID);
				}

				// Document : keep / delete + clean unused / keep unused
				if (in_array($itemtype,$this->DOCUMENTS_TYPES)) {
					$this->transferDocuments($itemtype,$ID,$newID);
				}

				// transfer compatible printers
				if ($itemtype==CARTRIDGE_TYPE) {
					$this->transferCompatiblePrinters($ID,$newID);
				}

				// Cartridges  and cartridges items linked to printer
				if ($itemtype==PRINTER_TYPE) {
					$this->transferPrinterCartridges($ID,$newID);
				}
				// TODO Init transfer of contract / docs / software : check unused : if not ? what to do ?
				//if ($this->inittype==$itemtype&&$itemtype==DOCUMENT_TYPE&&$ID==$newID) {
				
				//}

				// TODO Users ???? : Update right to new entity ?
				// TODO Linked Users ???? : Update right to new entity ?
				
				
				// Transfer Item
				$input=array("ID"=>$newID,'entities_id' => $this->to);
				// Manage Location dropdown
				if (isset($cinew->obj->fields['locations_id'])){
					$input['locations_id']=$this->transferDropdownLocation($cinew->obj->fields['locations_id']);
				}
				// Transfer Document file if exists (not to do if same entity) / Only for copy document
				if ($itemtype==DOCUMENT_TYPE&&$ID!=$newID
					&&!empty($cinew->obj->fields['filename'])
					&&$cinew->obj->fields['entities_id']!=$this->to
				){
					$input['filename']=$this->transferDocumentFile($cinew->obj->fields['filename']);
				}

				$cinew->obj->update($input);
				$this->addToAlreadyTransfer($itemtype,$ID,$newID);
				doHook("item_transfer",array("type"=>$itemtype, "ID" => $ID, "newID"=>$newID));
			}
		}
	}
	
	/**
	* Add an item to already transfer array
	*
	*@param $itemtype item type
	*@param $ID item original ID
	*@param $newID item new ID
	**/
	function addToAlreadyTransfer($itemtype,$ID,$newID){
		if (!isset($this->already_transfer[$itemtype])){
			$this->already_transfer[$itemtype]=array();
		}
		$this->already_transfer[$itemtype][$ID]=$newID;
	}
	
	/**
	* Transfer document file / copy file 
	*
	*@param $filename filename of the document ot copy
	*@return string new doc path
	**/
	function transferDocumentFile($filename){
		if (is_file(GLPI_DOC_DIR."/".$filename)){
			$splitter=explode("/",$filename);
			if (count($splitter)==2){
				$dir=$splitter[0];
				$file=$splitter[1];
				// Save message
				$tmp=$_SESSION["MESSAGE_AFTER_REDIRECT"];
				$new_path=getUploadFileValidLocationName($dir,$file,0);
				// Restore message
				$_SESSION["MESSAGE_AFTER_REDIRECT"]=$tmp;
				if (copy(GLPI_DOC_DIR."/".$filename,GLPI_DOC_DIR."/".$new_path)){
					return $new_path;
				}
			} 
		}
		return "";
	}

	/**
	* Transfer location
	*
	*@param $locID location ID
	*@return new location ID
	**/
	function transferDropdownLocation($locID){
		global $DB;

		if ($locID>0){
			if (isset($this->already_transfer['locations_id'][$locID])){
				return $this->already_transfer['locations_id'][$locID];
			} else { // Not already transfer
				// Search init item
				$query="SELECT * FROM glpi_locations WHERE ID='$locID'";
				if ($result=$DB->query($query)){
					if ($DB->numrows($result)){
						$data=$DB->fetch_array($result);
						$data=addslashes_deep($data);
						// Search if the location already exists in the destination entity
							$query="SELECT ID FROM glpi_locations WHERE entities_id='".$this->to."' AND completename='".$data['completename']."'";	
							if ($result_search=$DB->query($query)){
								// Found : -> use it
								if ($DB->numrows($result_search)>0){
									$newID=$DB->result($result_search,0,'ID');
									$this->addToAlreadyTransfer('locations_id',$locID,$newID);
									return $newID;
								}
							}
							// Not found : 
							$input=array();
							$input['tablename']='glpi_locations';
							$input['entities_id']=$this->to;
							$input['value']=$data['name'];
							$input['comments']=$data['comments'];
							$input['type']="under";
							$input['value2']=0; // locations_id
							// if locations_id>0 : transfer parent ID
							if ($data['locations_id']>0){
								$input['value2']=$this->transferDropdownLocation($data['locations_id']);
							}
							// add item
							$newID=addDropdown($input);
							$this->addToAlreadyTransfer('locations_id',$locID,$newID);
							return $newID;
					} 
				}
			}
		}
		return 0;
	}
	
	/**
	* Transfer netpoint
	*
	*@param $netID netpoint ID
	*@return new netpoint ID
	**/
	function transferDropdownNetpoint($netID){
		global $DB;

		if ($netID>0){
			if (isset($this->already_transfer['netpoint'][$netID])){
				return $this->already_transfer['netpoint'][$netID];
			} else { // Not already transfer
				// Search init item
				$query="SELECT * FROM glpi_netpoints WHERE ID='$netID'";
				if ($result=$DB->query($query)){
					if ($DB->numrows($result)){
						$data=$DB->fetch_array($result);
						$data=addslashes_deep($data);
						$locID=$this->transferDropdownLocation($data['locations_id']);
						// Search if the locations_id already exists in the destination entity
							$query="SELECT ID FROM glpi_netpoints WHERE entities_id='".$this->to."' AND name='".$data['name']."' AND locations_id='$locID'";	
							if ($result_search=$DB->query($query)){
								// Found : -> use it
								if ($DB->numrows($result_search)>0){
									$newID=$DB->result($result_search,0,'ID');
									$this->addToAlreadyTransfer('netpoint',$netID,$newID);
									return $newID;
								}
							}
							// Not found : 
							$input=array();
							$input['tablename']='glpi_netpoints';
							$input['entities_id']=$this->to;
							$input['value']=$data['name'];
							$input['comments']=$data['comments'];
							$input['type']="under";
							$input['value2']=$locID; 
							// add item
							$newID=addDropdown($input);
							$this->addToAlreadyTransfer('netpoint',$netID,$newID);
							return $newID;
					} 
				}
			}
		}
		return 0;
	}	
	
	/**
	* Transfer cartridges of a printer
	*
	*@param $ID original ID of the printer
	*@param $newID new ID of the printer
	**/
	function transferPrinterCartridges($ID,$newID){
		global $DB;
		
		// Get cartrdiges linked
		$query = "SELECT *
			FROM glpi_cartridges 
			WHERE glpi_cartridges.printers_id = '$ID'";
		if ($result = $DB->query($query)) {
			if ($DB->numrows($result)>0) { 
				$cart=new Cartridge();
				$carttype=new CartridgeType();

				while ($data=$DB->fetch_array($result)){
					$need_clean_process=false;
					// Foreach cartridges
					// if keep 
					if ($this->options['keep_cartridges_type']){ 
						$newcartID=-1;
						$newcarttypeID=-1;
						// 1 - Search carttype destination ?
						// Already transfer carttype : 
						if (isset($this->already_transfer[CARTRIDGE_TYPE][$data['cartridgesitems_id']])){
							$newcarttypeID=$this->already_transfer[CARTRIDGE_TYPE][$data['cartridgesitems_id']];
						} else {
							// Not already transfer cartype
							$query="SELECT count(*) AS CPT 
								FROM glpi_cartridges
								WHERE glpi_cartridges.cartridgesitems_id='".$data['cartridgesitems_id']."' 
								AND glpi_cartridges.printers_id > 0 AND glpi_cartridges.printers_id NOT IN ".$this->item_search[PRINTER_TYPE];
							$result_search=$DB->query($query);
							// Is the carttype will be completly transfer ?
							if ($DB->result($result_search,0,'CPT')==0){
								// Yes : transfer
								$need_clean_process=false;
								$this->transferItem(CARTRIDGE_TYPE,$data['cartridgesitems_id'],$data['cartridgesitems_id']);
								$newcarttypeID=$data['cartridgesitems_id'];
							} else {
								// No : copy carttype
								$need_clean_process=true;
								$carttype->getFromDB($data['cartridgesitems_id']);
								// Is existing carttype in the destination entity ?
								$query="SELECT * FROM glpi_cartridgesitems WHERE entities_id='".$this->to."' AND name='".addslashes($carttype->fields['name'])."'";
								if ($result_search=$DB->query($query)){
									if ($DB->numrows($result_search)>0){
										$newcarttypeID=$DB->result($result_search,0,'ID');
									}
								}
								// Not found -> transfer copy
								if ($newcarttypeID<0){
									// 1 - create new item
									unset($carttype->fields['ID']);
									$input=$carttype->fields;
									$input['entities_id']=$this->to;
									unset($carttype->fields);
									$newcarttypeID=$carttype->add($input);
									// 2 - transfer as copy
									$this->transferItem(CARTRIDGE_TYPE,$data['cartridgesitems_id'],$newcarttypeID);
								}
								// Founded -> use to link : nothing to do
							}
						}
						
						// Update cartridge if needed
						if ($newcarttypeID>0&&$newcarttypeID!=$data['cartridgesitems_id']){
							$cart->update(array("ID"=>$data['ID'],'cartridgesitems_id' => $newcarttypeID));		
						}
					} else { // Do not keep 
						// If same printer : delete cartridges
						if ($ID==$newID){
							$del_query="DELETE FROM glpi_cartridges 
								WHERE printers_id = '$ID'";
							$DB->query($del_query);
						}
						$need_clean_process=true;
					}
					// CLean process
					if ($need_clean_process&&$this->options['clean_cartridges_type']){
						// Clean carttype
						$query2 = "SELECT COUNT(*) AS CPT
								FROM glpi_cartridges 
								WHERE cartridgesitems_id = '" . $data['cartridgesitems_id'] . "'";
						$result2 = $DB->query($query2);
						if ($DB->result($result2, 0, 'CPT') == 0) {
							if ($this->options['clean_cartridges_type']==1){ // delete
								$carttype->delete(array ("ID" => $data['cartridgesitems_id']));
							}
							if ($this->options['clean_cartridges_type']==2){ // purge
								$carttype->delete(array ("ID" => $data['cartridgesitems_id']),1);
							}
						}
					}

				}
			}
		}
	
	}
	
		/**
	 * Copy (if needed) One software to the destination entity
	 * 
	 * @param $ID of the software
	 * 
	 * @return $ID of the new software (could be the same)
	 */
	function copySingleSoftware ($ID) {
		global $DB;
		
		if (isset($this->already_transfer[SOFTWARE_TYPE][$ID])){
			return $this->already_transfer[SOFTWARE_TYPE][$ID];
		}
		$soft=new Software();
		if ($soft->getFromDB($ID)) {
			// error_log("copySingleSoftware: ".$soft->fields['name']);
			
			if ($soft->fields['recursive']
               && in_array($soft->fields['entities_id'],getAncestorsOf("glpi_entities",$this->to))) {
				// no need to copy
				$newsoftID = $ID;
			} else {				
				$query="SELECT * FROM glpi_softwares WHERE entities_id=".$this->to." AND name='".addslashes($soft->fields['name'])."'";
				if ($data=$DB->request($query)->next()) {
					$newsoftID=$data["ID"];
				} else {			
					// create new item (don't check if move possible => clean needed)
					unset($soft->fields['ID']);
					$input=$soft->fields;
					$input['entities_id']=$this->to;
					unset($soft->fields);
					$newsoftID=$soft->add($input);
				}
			}
						
			$this->addToAlreadyTransfer(SOFTWARE_TYPE,$ID,$newsoftID);
			return $newsoftID;
		}
		return -1;
	}

	/**
	 * Copy (if needed) One softwareversion to the Dest Entity
	 * 
	 * @param $ID of the version
	 * 
	 * @return $ID of the new version (could be the same)
	 */
	function copySingleVersion ($ID) {
		global $DB;
		
		if (isset($this->already_transfer[SOFTWAREVERSION_TYPE][$ID])){
			return $this->already_transfer[SOFTWAREVERSION_TYPE][$ID];
		}

		$vers=new SoftwareVersion();
		if ($vers->getFromDB($ID)) {
			// error_log("copySingleVersion: ".$vers->fields['name']);
			
			$newsoftID = $this->copySingleSoftware($vers->fields['sID']);

			if ($newsoftID == $vers->fields['sID']) {
				// no need to copy
				$newversID = $ID;
			} else {				
				$query="SELECT ID FROM glpi_softwaresversions WHERE sID=$newsoftID AND  name='".addslashes($vers->fields['name'])."'";			
				if ($data=$DB->request($query)->next()) {
					$newversID=$data["ID"];
				} else {
					// create new item (don't check if move possible => clean needed)
					unset($vers->fields['ID']);
					$input=$vers->fields;
					unset($vers->fields);
					$input['sID']=$newsoftID;
					$newversID=$vers->add($input);
				}
			}
			
			$this->addToAlreadyTransfer(SOFTWAREVERSION_TYPE,$ID,$newversID);
			return $newversID;
		}
		return -1;
	}
	/**
	* Transfer softwares of a computer
	*
	*@param $ID ID of the computer
	*@param $ocs_computer ID of the computer in OCS if imported from OCS
	**/
	function transferComputerSoftwares($ID,$ocs_computer=false){
		global $DB;

		// Get Installed version
		$query = "SELECT *	FROM glpi_computers_softwaresversions 
			WHERE cID = $ID AND vID NOT IN ".$this->item_recurs[SOFTWAREVERSION_TYPE];
			
		foreach ($DB->request($query) AS $data) {
			
			if ($this->options['keep_softwares']){

				$newversID = $this->copySingleVersion($data['vID']);
				
				if ($newversID>0 && $newversID!=$data['vID']){
					$query="UPDATE glpi_computers_softwaresversions SET vID=$newversID WHERE ID=".$data['ID'];
					$DB->query($query);	
				}
				
			} else { // Do not keep 

				// Delete inst software for computer
				$del_query="DELETE FROM glpi_computers_softwaresversions WHERE ID = ".$data['ID'];
				$DB->query($del_query);

				if ($ocs_computer){
					$query="UPDATE glpi_ocslinks SET import_software = NULL WHERE glpi_id=$ID";
					$DB->query($query);
				}	
			}
		} // each installed version

		// Affected licenses 
		if ($this->options['keep_softwares']){
			$query = "SELECT *	FROM glpi_softwareslicenses
				WHERE computers_id = '$ID'";
			
			foreach ($DB->request($query) AS $data) {
				$this->transferItem(SOFTWARELICENSE_TYPE,$data['ID'],$data['ID']);
			}
			
		} else {
			$query="UPDATE glpi_softwareslicenses SET computers_id = -1 WHERE computers_id='$ID'";			
			$DB->query($query);
		}		
	}
	/**
	* Transfer softwares of a license
	*
	*@param $ID ID of the License
	*
	**/
	function transferLicenseSoftwares($ID){
		global $DB;

		if ($this->inittype == SOFTWARE_TYPE) {
			// All version will be move with the software
			return;
		}
		$license = new SoftwareLicense();
		if ($license->getFromDB($ID)) {
			$input=array();

			$newsoftID = $this->copySingleSoftware($license->fields['sID']);
			if ($newsoftID>0 && $newsoftID!=$license->fields['sID']){
				$input['sID']=$newsoftID;
			}

			foreach (array("buy_version","use_version") as $field) {			
				if ($license->fields[$field]>0) {
					$newversID = $this->copySingleVersion($license->fields[$field]);
					
					if ($newversID>0 && $newversID!=$license->fields[$field]){
						$input[$field] = $newversID;
					}
				}
			}

			if (count($input)) {
				$input['ID'] = $ID;
				$license->update($input);
			}			
		} // getFromDB
	}

	/**
	* Transfer License and Version of a Software
	*
	*@param $ID ID of the Software
	*
	**/
	function transferSoftwareLicensesAndVersions($ID){
		global $DB;

		$query = "SELECT ID FROM glpi_softwareslicenses
			WHERE sID = '$ID'";
		
		foreach ($DB->request($query) AS $data) {
			$this->transferItem(SOFTWARELICENSE_TYPE,$data['ID'],$data['ID']);
		}

		$query = "SELECT ID FROM glpi_softwaresversions
			WHERE sID = '$ID'";
		
		foreach ($DB->request($query) AS $data) {
			// Just Store the info.
			$this->addToAlreadyTransfer(SOFTWAREVERSION_TYPE,$data['ID'],$data['ID']);
		}
	}

	function cleanSoftwareVersions() {
		
		if (!isset($this->already_transfer[SOFTWAREVERSION_TYPE])) return;
		
		$vers=new SoftwareVersion();
		
		foreach ($this->already_transfer[SOFTWAREVERSION_TYPE] AS $old => $new) {

			if    (countElementsInTable("glpi_softwareslicenses","buy_version=$old")==0
				&& countElementsInTable("glpi_softwareslicenses","use_version=$old")==0
				&& countElementsInTable("glpi_computers_softwaresversions","vID=$old")==0) {

				// if ($vers->getFromDB($old)) error_log("cleanSoftwareVersions: ".$vers->fields['name']);
				
				$vers->delete(array("ID" => $old));
			}
		}
	}
	function cleanSoftwares() {
		
		if (!isset($this->already_transfer[SOFTWARE_TYPE])) return;
		
		$soft=new Software();
		
		foreach ($this->already_transfer[SOFTWARE_TYPE] AS $old => $new) {

			if    (countElementsInTable("glpi_softwareslicenses","sID=$old")==0
				&& countElementsInTable("glpi_softwaresversions","sID=$old")==0) {

				// if ($soft->getFromDB($old)) error_log("cleanSoftwares: ".$soft->fields['name']);

				if ($this->options['clean_softwares']==1){ // delete
					$soft->delete(array("ID" => $old),0);
				}
				else if ($this->options['clean_softwares']==2){ // purge
					$soft->delete(array("ID" => $old),1);
				}
			}
		}
	}
	
	/* previous function
	function transferSoftwares($itemtype,$ID,$ocs_computer=false){
		global $DB;
		// Get licenses linked
		$query = "SELECT glpi_softwaresversions.sID as softID, glpi_softwaresversions.ID as versID, glpi_computers_softwaresversions.ID as instID
			FROM glpi_computers_softwaresversions 
			LEFT JOIN glpi_softwaresversions ON (glpi_computers_softwaresversions.vID = glpi_softwaresversions.ID)
			WHERE glpi_computers_softwaresversions.cID = '$ID' AND glpi_softwaresversions.sID NOT IN ".$this->item_recurs[SOFTWARE_TYPE];
			
		if ($result = $DB->query($query)) {
			if ($DB->numrows($result)>0) { 
				$vers=new SoftwareVersion();
				$soft=new Software();

				while ($data=$DB->fetch_array($result)){
					$need_clean_process=false;
					// Foreach licenses
					// if keep 
					if ($this->options['keep_softwares']){ 
						if (!empty($data['softID'])&&$data['softID']>0
						&&!empty($data['versID'])&&$data['versID']>0
						&&!empty($data['instID'])&&$data['instID']>0){
							$newversID=-1;
							// Already_transfer version
							if (isset($this->already_transfer[SOFTWAREVERSION_TYPE][$data['versID']])){
								// Copy version : update link in inst_software
								if ($this->already_transfer[SOFTWAREVERSION_TYPE][$data['versID']]!=$data['versID']){
									$newversID=$this->already_transfer[SOFTWAREVERSION_TYPE][$data['versID']];
									$need_clean_process=true;
								} 
								// Same version : nothing to do
							} else {
							// Not already transfer version 
								$newsoftID=-1;
								// 1 - Search software destination ?
								// Already transfer soft : 
								if (isset($this->already_transfer[SOFTWARE_TYPE][$data['softID']])){
									$newsoftID=$this->already_transfer[SOFTWARE_TYPE][$data['softID']];
								} else {
									// Not already transfer soft
									$query="SELECT count(*) AS CPT 
										FROM glpi_computers_softwaresversions INNER JOIN glpi_softwaresversions ON (glpi_computers_softwaresversions.vID = glpi_softwaresversions.ID)
										WHERE glpi_softwaresversions.sID='".$data['softID']."' AND glpi_computers_softwaresversions.cID NOT IN ".$this->item_search[COMPUTER_TYPE];
									$result_search=$DB->query($query);
									// Is the software will be completly transfer ?
									if ($DB->result($result_search,0,'CPT')==0){
										// Yes : transfer
										$need_clean_process=false;
										$this->transferItem(SOFTWARE_TYPE,$data['softID'],$data['softID']);
										$newsoftID=$data['softID'];
									} else {
										// No : copy software
										$need_clean_process=true;
										$soft->getFromDB($data['softID']);
										// Is existing software in the destination entity ?
										$query="SELECT * FROM glpi_softwares WHERE entities_id='".$this->to."' AND name='".addslashes($soft->fields['name'])."'";
										if ($result_search=$DB->query($query)){
											if ($DB->numrows($result_search)>0){
												$newsoftID=$DB->result($result_search,0,'ID');
											}
										}
										// Not found -> transfer copy
										if ($newsoftID<0){
											// 1 - create new item
											unset($soft->fields['ID']);
											$input=$soft->fields;
											$input['entities_id']=$this->to;
											unset($soft->fields);
											$newsoftID=$soft->add($input);
											// 2 - transfer as copy
											$this->transferItem(SOFTWARE_TYPE,$data['softID'],$newsoftID);
										}
										// Founded -> use to link : nothing to do
									}
								}
								// 2 - Transfer version
								if ($newsoftID>0&&$newsoftID!=$data['softID']){
								// destination soft <> original soft -> copy soft
									$query="SELECT count(*) AS CPT 
										FROM glpi_computers_softwaresversions 
										WHERE glpi_computers_softwaresversions.vID='".$data['versID']."' AND glpi_computers_softwaresversions.cID NOT IN ".$this->item_search[COMPUTER_TYPE];
									$result_search=$DB->query($query);
									// Is the version will be completly transfer ?
									if ($DB->result($result_search,0,'CPT')==0){
										// Yes : transfer version to copy software
										$vers->update(array("ID"=>$data['versID'],'sID' => $newsoftID));
										$this->addToAlreadyTransfer(SOFTWAREVERSION_TYPE,$data['versID'],$data['versID']);
									} else {
										$vers->getFromDB($data['versID']);
										// No : Search licence
										$query="SELECT ID 
											FROM glpi_softwaresversions WHERE sID='$newsoftID' AND  version='".addslashes($vers->fields['name'])."'";
										if ($result_search=$DB->query($query)){
											if ($DB->numrows($result_search)>0){
												$newversID=$DB->result($result_search,0,'ID');
											}
										}
										if ($newversID<0){
											// Not found : copy license
											unset($vers->fields['ID']);
											$input=$vers->fields;
											unset($vers->fields);
											$input['sID']=$newsoftID;
											$newversID=$vers->add($input);
										}
										$this->addToAlreadyTransfer(SOFTWAREVERSION_TYPE,$data['versID'],$newversID);
										// Found : use it 
									}
								} 
								// else destination soft = original soft -> nothing to do / keep links
							}
							// Update inst software if needed
							if ($newversID>0&&$newversID!=$data['versID']){
								$query="UPDATE glpi_computers_softwaresversions SET vID='$newversID' WHERE ID='".$data['instID']."'";
								$DB->query($query);	
							}
						} else {
							// Bad inst data : delete them
							$del_query="DELETE FROM glpi_computers_softwaresversions 
								WHERE ID = '".$data['instID']."'";
							$DB->query($del_query);
							$need_clean_process=false;
						}
					} else { // Do not keep 
						// Delete inst software for computer
						$del_query="DELETE FROM glpi_computers_softwaresversions 
							WHERE ID = '".$data['instID']."'";
						$DB->query($del_query);
						$need_clean_process=true;
						if ($ocs_computer){
							$query="UPDATE glpi_ocslinks SET import_software = NULL WHERE glpi_id='$ID'";
							$DB->query($query);
						}
					}
					// CLean process
					if ($need_clean_process&&$this->options['clean_softwares']){
						// Clean license
						$query2 = "SELECT COUNT(*) AS CPT
								FROM glpi_computers_softwaresversions 
								WHERE vID = '" . $data['versID'] . "'";
						$result2 = $DB->query($query2);
						if ($DB->result($result2, 0, 'CPT') == 0) {
							$vers->delete(array (
								"ID" => $data['versID']
							));
						}
						// Clean software
						$query2 = "SELECT COUNT(*) AS CPT
								FROM glpi_softwaresversions 
								WHERE sID = '" . $data['softID'] . "'";
						$result2 = $DB->query($query2);
						if ($DB->result($result2, 0, 'CPT') == 0) {
							if ($this->options['clean_softwares']==1){ // delete
								$soft->delete(array ("ID" => $data['softID']));
							}
							if ($this->options['clean_softwares']==2){ // purge
								$soft->delete(array ("ID" => $data['softID']),1);
							}
						}
					}

				}
			}
		}
	}*/
	
	/**
	* Transfer contracts
	*
	*@param $itemtype original type of transfered item
	*@param $ID original ID of the contract
	*@param $newID new ID of the contract
	**/
	function transferContracts($itemtype,$ID,$newID){
		global $DB;
		$need_clean_process=false;

		// if keep 
		if ($this->options['keep_contracts']){
			$contract=new Contract();
			// Get contracts for the item
			$query="SELECT * FROM glpi_contracts_items" .
					" WHERE items_id = '$ID' AND itemtype = '$itemtype' AND contracts_id NOT IN ".$this->item_recurs[CONTRACT_TYPE];
	
			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					// Foreach get item 
					while ($data=$DB->fetch_array($result)) {
						$need_clean_process=false;
						$item_ID=$data['contracts_id'];
						$newcontractID=-1;
						// is already transfer ?
						if (isset($this->already_transfer[CONTRACT_TYPE][$item_ID])){
							$newcontractID=$this->already_transfer[CONTRACT_TYPE][$item_ID];
							if ($newcontractID!=$item_ID){
								$need_clean_process=true;
							}
						} else {
							// No
							// Can be transfer without copy ? = all linked items need to be transfer (so not copy)
							$canbetransfer=true;
							$query="SELECT DISTINCT itemtype FROM glpi_contracts_items WHERE contracts_id='$item_ID'";
							
							if ($result_type = $DB->query($query)) {
								if ($DB->numrows($result_type)>0) {
									while (($data_type=$DB->fetch_array($result_type)) && $canbetransfer) {
										$dtype=$data_type['itemtype'];
										if (isset($this->item_search[$dtype])){
											// No items to transfer -> exists links
											$query_search="SELECT count(*) AS CPT 
													FROM glpi_contracts_items 
													WHERE contracts_id='$item_ID' AND itemtype='$dtype' AND items_id NOT IN ".$this->item_search[$dtype];
											$result_search = $DB->query($query_search);
											if ($DB->result($result_search,0,'CPT')>0){
												$canbetransfer=false;
											}
										} else {
											$canbetransfer=false;
										}
									}
								}
							}
							// Yes : transfer 
							if ($canbetransfer){
								$this->transferItem(CONTRACT_TYPE,$item_ID,$item_ID);
								$newcontractID=$item_ID;
							} else {
								$need_clean_process=true;
								$contract->getFromDB($item_ID);
								// No : search contract
								$query="SELECT * FROM glpi_contracts WHERE entities_id='".$this->to."' AND name='".addslashes($contract->fields['name'])."'";
								if ($result_search=$DB->query($query)){
									if ($DB->numrows($result_search)>0){
										$newcontractID=$DB->result($result_search,0,'ID');
										$this->addToAlreadyTransfer(CONTRACT_TYPE,$item_ID,$newcontractID);
									}
								}
								// found : use it
								// not found : copy contract
								if ($newcontractID<0){
									// 1 - create new item
									unset($contract->fields['ID']);
									$input=$contract->fields;
									$input['entities_id']=$this->to;
									unset($contract->fields);
									$newcontractID=$contract->add($input);
									// 2 - transfer as copy
									$this->transferItem(CONTRACT_TYPE,$item_ID,$newcontractID);
								}
							}
						}
						// Update links 
						if ($ID==$newID){
							if ($item_ID!=$newcontractID){
								$query="UPDATE glpi_contracts_items SET contracts_id = '$newcontractID' WHERE ID='".$data['ID']."'";
								$DB->query($query);
							}
							// Same Item -> update links
						} else {
							// Copy Item -> copy links
							if ($item_ID!=$newcontractID){
								$query="INSERT INTO glpi_contracts_items (contracts_id,items_id,itemtype) VALUES ('$newcontractID','$newID','$itemtype')";
								$DB->query($query);
							} else { // same contract for new item update link
								$query="UPDATE glpi_contracts_items SET items_id = '$newID' WHERE ID='".$data['ID']."'";
								$DB->query($query);
							}
						}
						// If clean and unused -> 
						if ($need_clean_process&&$this->options['clean_contracts']){
							$query = "SELECT COUNT(*) AS CPT 
								FROM glpi_contracts_items 
								WHERE contracts_id='$item_ID'";
							if ($result_remaining=$DB->query($query)){
								if ($DB->result($result_remaining,0,'CPT')==0){
									if ($this->options['clean_contracts']==1){
										$contract->delete(array('ID'=>$item_ID));
									} 
									if ($this->options['clean_contracts']==2) { // purge
										$contract->delete(array('ID'=>$item_ID),1);
									}
								}
							}
						}
					}
				}
			}
		} else {// else unlink
			$query="DELETE FROM glpi_contracts_items WHERE items_id = '$ID' AND itemtype = '$itemtype'";
			$DB->query($query);
		}

	}

	/**
	* Transfer documents
	*
	*@param $itemtype original type of transfered item
	*@param $ID original ID of the document
	*@param $newID new ID of the document
	**/
	function transferDocuments($itemtype,$ID,$newID){
		global $DB;
		$need_clean_process=false;

		// if keep 
		if ($this->options['keep_documents']){
			$document=new Document();
			// Get contracts for the item
			$query="SELECT * FROM glpi_documents_items WHERE items_id = '$ID' AND itemtype = '$itemtype' AND documents_id NOT IN ".$this->item_recurs[DOCUMENT_TYPE];
			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					// Foreach get item 
					while ($data=$DB->fetch_array($result)) {
						$need_clean_process=false;
						$item_ID=$data['documents_id'];
						$newdocID=-1;
						// is already transfer ?
						if (isset($this->already_transfer[DOCUMENT_TYPE][$item_ID])){
							$newdocID=$this->already_transfer[DOCUMENT_TYPE][$item_ID];
							if ($newdocID!=$item_ID){
								$need_clean_process=true;
							}
						} else {
							// No
							// Can be transfer without copy ? = all linked items need to be transfer (so not copy)
							$canbetransfer=true;
							$query="SELECT DISTINCT itemtype FROM glpi_documents_items WHERE documents_id='$item_ID'";
							
							if ($result_type = $DB->query($query)) {
								if ($DB->numrows($result_type)>0) {
									while (($data_type=$DB->fetch_array($result_type)) && $canbetransfer) {
										$dtype=$data_type['itemtype'];
										if (isset($this->item_search[$dtype])) {
											// No items to transfer -> exists links
											$query_search="SELECT count(*) AS CPT 
													FROM glpi_documents_items 
													WHERE documents_id='$item_ID' AND itemtype='$dtype' AND items_id NOT IN ".$this->item_search[$dtype];
											// contacts, contracts, and enterprises are linked as device. 
											if (isset($this->item_recurs[$dtype])) {
												$query_search .= " AND items_id NOT IN ".$this->item_recurs[$dtype];
											}
											$result_search = $DB->query($query_search);
											if ($DB->result($result_search,0,'CPT')>0){
												$canbetransfer=false;
											}
										}
									}
								}
							}
							// Yes : transfer 
							if ($canbetransfer){
								$this->transferItem(DOCUMENT_TYPE,$item_ID,$item_ID);
								$newdocID=$item_ID;
							} else {
								$need_clean_process=true;
								$document->getFromDB($item_ID);
								// No : search contract
								$query="SELECT * FROM glpi_documents WHERE entities_id='".$this->to."' AND name='".addslashes($document->fields['name'])."'";
								if ($result_search=$DB->query($query)){
									if ($DB->numrows($result_search)>0){
										$newdocID=$DB->result($result_search,0,'ID');
										$this->addToAlreadyTransfer(DOCUMENT_TYPE,$item_ID,$newdocID);
									}
								}
								// found : use it
								// not found : copy doc
								if ($newdocID<0){
									// 1 - create new item
									unset($document->fields['ID']);
									$input=$document->fields;
									// Not set new entity Do by transferItem 
									//$input['entities_id']=$this->to;
									unset($document->fields);
									$newdocID=$document->add($input);
									// 2 - transfer as copy
									$this->transferItem(DOCUMENT_TYPE,$item_ID,$newdocID);
								}
							}
						}
						// Update links 
						if ($ID==$newID){
							if ($item_ID!=$newdocID){
								$query="UPDATE glpi_documents_items SET documents_id = '$newdocID' WHERE ID='".$data['ID']."'";
								$DB->query($query);
							}
							// Same Item -> update links
						} else {
							// Copy Item -> copy links
							if ($item_ID!=$newdocID){
								$query="INSERT INTO glpi_documents_items (documents_id,items_id,itemtype) VALUES ('$newdocID','$newID','$itemtype')";
								$DB->query($query);
							} else { // same doc for new item update link
								$query="UPDATE glpi_documents_items SET items_id = '$newID' WHERE ID='".$data['ID']."'";
								$DB->query($query);
							}
						}
						// If clean and unused -> 
						if ($need_clean_process&&$this->options['clean_documents']){
							$query = "SELECT COUNT(*) AS CPT 
								FROM glpi_documents_items 
								WHERE documents_id='$item_ID'";
							if ($result_remaining=$DB->query($query)){
								if ($DB->result($result_remaining,0,'CPT')==0){
									if ($this->options['clean_documents']==1){
										$document->delete(array('ID'=>$item_ID));
									} 
									if ($this->options['clean_documents']==2) { // purge
										$document->delete(array('ID'=>$item_ID),1);
									}
								}
							}
						}
					}
				}
			}
		} else {// else unlink
			$query="DELETE FROM glpi_documents_items WHERE items_id = '$ID' AND itemtype = '$itemtype'";
			$DB->query($query);
		}

	}

	/**
	* Delete direct connection for a linked item 
	*
	*@param $itemtype original type of transfered item
	*@param $ID ID of the item
	*@param $link_type type of the linked items to transfer
	*@param $ocs_computer if computer type OCS ID of the item if available
	**/
	function transferDirectConnection($itemtype,$ID,$link_type,$ocs_computer=false){
		global $DB,$LINK_ID_TABLE;
		// Only same Item case : no duplication of computers
		// Default : delete
		$keep=0;
		$clean=0;
		$ocs_field="";

		switch ($link_type){
			case PRINTER_TYPE:
				$keep=$this->options['keep_dc_printer'];
				$clean=$this->options['clean_dc_printer'];
				$ocs_field="import_printer";
				break;
			case MONITOR_TYPE:
				$keep=$this->options['keep_dc_monitor'];
				$clean=$this->options['clean_dc_monitor'];
				$ocs_field="import_monitor";
				break;
			case PERIPHERAL_TYPE:
				$keep=$this->options['keep_dc_peripheral'];
				$clean=$this->options['clean_dc_peripheral'];
				$ocs_field="import_peripheral";
				break;
			case PHONE_TYPE:
				$keep=$this->options['keep_dc_phone'];
				$clean=$this->options['clean_dc_phone'];
				break;
		}

		$ci=new CommonItem();
		// Get connections
		$query = "SELECT * 
			FROM glpi_computers_items 
			WHERE computers_id='$ID' AND itemtype='".$link_type."'";
		if (isset($CFG_GLPI["recursive_type"][$link_type])){
			$query .= " AND items_id NOT IN ".$this->item_recurs[$link_type];
		}	
		
		if ($result = $DB->query($query)) {
			if ($DB->numrows($result)!=0) { 
				// Foreach get item 
				while ($data=$DB->fetch_array($result)) {
					$item_ID=$data['items_id'];
					if ($ci->getFromDB($link_type,$item_ID)) {
						// If global :
						if ($ci->obj->fields['is_global']==1){
							$need_clean_process=false;
							// if keep 
							if ($keep){
								$newID=-1;
								// Is already transfer ? 
								if (isset($this->already_transfer[$link_type][$item_ID])){
									$newID=$this->already_transfer[$link_type][$item_ID];
									// Already transfer as a copy : need clean process
									if ($newID!=$item_ID){
										$need_clean_process=true;
									}
								} else { // Not yet tranfer
									// Can be managed like a non global one ? = all linked computers need to be transfer (so not copy)
									$query="SELECT count(*) AS CPT 
										FROM glpi_computers_items 
										WHERE itemtype='".$link_type."' AND items_id='$item_ID'
											AND computers_id NOT IN ".$this->item_search[COMPUTER_TYPE];
									$result_search=$DB->query($query);
									// All linked computers need to be transfer -> use unique transfer system
									if ($DB->result($result_search,0,'CPT')==0){
										
										$need_clean_process=false;
										$this->transferItem($link_type,$item_ID,$item_ID);
										$newID=$item_ID;
									} else { // else Transfer by Copy
										$need_clean_process=true;
										// Is existing global item in the destination entity ?
										$query="SELECT * 
											FROM ".$LINK_ID_TABLE[$link_type]." 
											WHERE is_global='1' AND entities_id='".$this->to."' 
												AND name='".addslashes($ci->getField('name'))."'";
										if ($result_search=$DB->query($query)){
											if ($DB->numrows($result_search)>0){
												$newID=$DB->result($result_search,0,'ID');
												$this->addToAlreadyTransfer($link_type,$item_ID,$newID);
											}
										}
										// Not found -> transfer copy
										if ($newID<0){
											// 1 - create new item
											unset($ci->obj->fields['ID']);
											$input=$ci->obj->fields;
											$input['entities_id']=$this->to;
											unset($ci->obj->fields);
											$newID=$ci->obj->add($input);
											// 2 - transfer as copy
											$this->transferItem($link_type,$item_ID,$newID);
										}
										// Founded -> use to link : nothing to do
									}
								}
								// Finish updated link if needed
								if ($newID>0&&$newID!=$item_ID){
									$query = "UPDATE glpi_computers_items 
									SET items_id='$newID' WHERE ID = '".$data['ID']."' ";
									$DB->query($query);
								}
							} else {
								// Else delete link
								
								// Call Disconnect for global device (no disconnect behavior, but history )
								Disconnect($data['ID'],1,false);

								$need_clean_process=true;
								// OCS clean link
								if ($ocs_computer&&!empty($ocs_field)){
									$query="UPDATE glpi_ocslinks SET `$ocs_field` = NULL WHERE glpi_id='$ID'";
									$DB->query($query);
								}

							}
							// If clean and not linked dc -> delete
							if ($need_clean_process&&$clean){
								$query = "SELECT COUNT(*) AS CPT
									FROM glpi_computers_items 
									WHERE items_id='$item_ID' AND itemtype='".$link_type."'";
								if ($result_dc=$DB->query($query)){
									if ($DB->result($result_dc,0,'CPT')==0){
										if ($clean==1){
											$ci->obj->delete(array('ID'=>$item_ID));
										} 
										if ($clean==2) { // purge
											$ci->obj->delete(array('ID'=>$item_ID),1);
										}
									}
								}
							}
						} else { // If unique : 
							//if keep -> transfer list else unlink
							if ($keep){
								$this->transferItem($link_type,$item_ID,$item_ID);
							} else {
								// Else delete link (apply disconnect behavior)
								Disconnect($data['ID']);

								//if clean -> delete
								if ($clean==1){
									$ci->obj->delete(array('ID'=>$item_ID));
								}
								else if ($clean==2){ // purge
									$ci->obj->delete(array('ID'=>$item_ID),1);
								}
								if ($ocs_computer&&!empty($ocs_field)){
									$query="UPDATE glpi_ocslinks SET `$ocs_field` = NULL WHERE glpi_id='$ID'";
									$DB->query($query);
								}
							}
						}
					} else {
						// Unexisting item / Force disconnect
						Disconnect($data['ID'],0,false);
					}
				}
			}
		}	
	}

	/**
	* Delete direct connection for a linked item 
	*
	*@param $ID ID of the item
	*@param $itemtype item type
	**/
	function deleteDirectConnection($itemtype,$ID){
		global $DB;
		// Delete Direct connection to computers for item type 
		$query = "SELECT * 
			FROM glpi_computers_items 
			WHERE items_id = '$ID' AND itemtype = '".$itemtype."'";
		$result = $DB->query($query);
	}

	/**
	* Transfer tickets
	*
	*@param $itemtype type of transfered item
	*@param $ID original ID of the ticket
	*@param $newID new ID of the ticket
	**/
	function transferTickets($itemtype,$ID,$newID){
		global $DB;
		$job= new Job();

		$query = "SELECT ID, suppliers_id_assign
			FROM glpi_tickets 
			WHERE items_id = '$ID' AND itemtype = '$itemtype'";
		if ($result = $DB->query($query)) {
			if ($DB->numrows($result)!=0) { 
				switch ($this->options['keep_tickets']){
					// Transfer
					case 2: 
						// Same Item / Copy Item -> update entity
						while ($data=$DB->fetch_array($result)) {
							$suppliers_id_assign=0;
							if ($data['suppliers_id_assign']>0){
								$suppliers_id_assign=$this->transferSingleEnterprise($data['suppliers_id_assign']);
							}
							$job->update(array("ID"=>$data['ID'],'entities_id' => $this->to, 'items_id'=>$newID, 'itemtype'=>$itemtype, 'suppliers_id_assign'=>$suppliers_id_assign));
							$this->addToAlreadyTransfer(TRACKING_TYPE,$data['ID'],$data['ID']);
						}
					break;
					// Clean ref : keep ticket but clean link
					case 1: 
						// Same Item / Copy Item : keep and clean ref
						while ($data=$DB->fetch_array($result)) {
							$suppliers_id_assign=0;
							if ($data['suppliers_id_assign']>0){
								$suppliers_id_assign=$this->transferSingleEnterprise($data['suppliers_id_assign']);
							}

							$job->update(array("ID"=>$data['ID'],'itemtype' => 0, 'items_id'=>0, 'suppliers_id_assign'=>$suppliers_id_assign));
							$this->addToAlreadyTransfer(TRACKING_TYPE,$data['ID'],$data['ID']);
						}
					break;
					// Delete
					case 0:
						// Same item -> delete
						if ($ID==$newID){
							while ($data=$DB->fetch_array($result)) {
								$job->delete(array('ID'=>$data['ID']));
							}
						}
						// Copy Item : nothing to do
					break;
				}
			}
		}
	}

	/**
	* Transfer history
	*
	*@param $itemtype original type of transfered item
	*@param $ID original ID of the history
	*@param $newID new ID of the history
	**/
	function transferHistory($itemtype,$ID,$newID){
		global $DB;

		switch ($this->options['keep_history']){
			// delete
			case 0 :  
				// Same item -> delete
				if ($ID==$newID){ 
					$query = "DELETE FROM glpi_logs 
						WHERE itemtype = '$itemtype' AND items_id = '$ID'";
					$result = $DB->query($query);
				}
				// Copy -> nothing to do
				break;
			// Keep history
			case 1 :	
			default : 
				// Copy -> Copy datas 
				if ($ID!=$newID){
					$query = "SELECT * FROM glpi_logs 
						WHERE itemtype = '$itemtype' AND items_id = '$ID'";
					$result=$DB->query($query);
					if ($result = $DB->query($query)) {
						if ($DB->numrows($result)!=0) { 
							while ($data=$DB->fetch_array($result)) {
								$data = addslashes_deep($data);
								$query = "INSERT INTO glpi_logs
								(items_id, itemtype, devicetype, linked_action, user_name, date_mod, id_search_option, old_value, new_value)
								VALUES
								('$newID','$itemtype','".$data['devicetype']."','".$data['linked_action']."','". $data['user_name']."', '".$data['date_mod']."', '".$data['id_search_option']."', '".$data['old_value']."', '".$data['new_value']."');";
								$DB->query($query);
							}
						}
					}
				}
				// Same item -> nothing to do
				break;
		}
	}
	/**
	* Transfer compatible printers for a cartridge type
	*
	*@param $ID original ID of the cartridge type
	*@param $newID new ID of the cartridge type
	**/
	function transferCompatiblePrinters($ID,$newID){
		global $DB;
		if ($ID!=$newID){
			
			$query="SELECT * FROM glpi_cartridges_printersmodels WHERE cartridgesitems_id='$ID'";
			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)!=0) { 
					
					$cartype=new CartridgeType();
					while ($data=$DB->fetch_array($result)) {
						$data = addslashes_deep($data);
						$cartype->addCompatibleType($newID,$data["printersmodels_id"]);
					}
				}
			}
			
		}
	}

	/**
	* Transfer infocoms of an item
	*
	*@param $itemtype type of the item to transfer
	*@param $ID original ID of the item 
	*@param $newID new ID of the item
	**/
	function transferInfocoms($itemtype,$ID,$newID){
		global $DB;

		$ic=new Infocom();
		if ($ic->getFromDBforDevice($itemtype,$ID)){
			switch ($this->options['keep_infocoms']){
				// delete
				case 0 :  
					// Same item -> delete
					if ($ID==$newID){ 
						$query = "DELETE FROM glpi_infocoms 
							WHERE itemtype = '$itemtype' AND items_id = '$ID'";
						$result = $DB->query($query);
					}
					// Copy : nothing to do
					break;
				// Keep
				case 1 : 
				default :
					// transfert enterprise 
					$suppliers_id=0; 
					if ($ic->fields['suppliers_id']>0){
						$suppliers_id=$this->transferSingleEnterprise($ic->fields['suppliers_id']);
					}
					// Copy : copy infocoms
					if ($ID!=$newID){
						// Copy items
						$input=$ic->fields;
						$input['items_id']=$newID;
						$input['suppliers_id']=$suppliers_id;
						unset($input['ID']);
						unset($ic->fields);
						$ic->add($input);
					} else {
						// Same Item : manage only enterprise move
						// Update enterprise
						if ($suppliers_id>0 && $suppliers_id!=$ic->fields['suppliers_id']){
							$ic->update(array('ID'=>$ic->fields['ID'],'suppliers_id'=>$suppliers_id));
						}
					}
					break;
			}
		}
	}
	/**
	* Transfer an enterprise
	*
	*@param $ID ID of the enterprise
	**/
	function transferSingleEnterprise($ID){
		global $DB;
		// TODO clean system : needed ?
		$ent=new Enterprise();
		if ($this->options['keep_enterprises']&&$ent->getFromDB($ID)){
			if (isset($this->noneedtobe_transfer[ENTERPRISE_TYPE][$ID])) {
				// recursive enterprise
				return $ID;
			} else if (isset($this->already_transfer[ENTERPRISE_TYPE][$ID])){
				// Already transfer
				return $this->already_transfer[ENTERPRISE_TYPE][$ID];
			} else {
				$newID=-1;
				// Not already transfer
				$links_remaining=0;
				// All linked items need to be transfer so transfer enterprise ?
				// Search for contract
				$query="SELECT count(*) AS CPT FROM glpi_contracts_suppliers WHERE suppliers_id='$ID' AND contracts_id NOT IN ".$this->item_search[CONTRACT_TYPE];
				$result_search=$DB->query($query);
				$links_remaining=$DB->result($result_search,0,'CPT');

				if ($links_remaining==0){
					// Search for infocoms
					if ($this->options['keep_infocoms']){
						foreach ($this->INFOCOMS_TYPES as $itemtype){
							$query="SELECT count(*) AS CPT FROM glpi_infocoms
								WHERE suppliers_id='$ID' AND itemtype='$itemtype' AND items_id NOT IN ".$this->item_search[$itemtype];
							if ($result_search = $DB->query($query)) {
								$links_remaining+=$DB->result($result_search,0,'CPT');
							}
						}
					}					
				}
				// All linked items need to be transfer -> use unique transfer system
				if ($links_remaining==0){
					$this->transferItem(ENTERPRISE_TYPE,$ID,$ID);
					$newID=$ID;
				} else { // else Transfer by Copy
					// Is existing item in the destination entity ?
					$query="SELECT * FROM glpi_suppliers WHERE entities_id='".$this->to."' AND name='".addslashes($ent->fields['name'])."'";
					if ($result_search=$DB->query($query)){
						if ($DB->numrows($result_search)>0){
							$newID=$DB->result($result_search,0,'ID');
							$this->addToAlreadyTransfer(ENTERPRISE_TYPE,$ID,$newID);
						}
					}
					// Not found -> transfer copy
					if ($newID<0){
						// 1 - create new item
						unset($ent->fields['ID']);
						$input=$ent->fields;
						$input['entities_id']=$this->to;
						unset($ent->fields);
						$newID=$ent->add($input);
						// 2 - transfer as copy
						$this->transferItem(ENTERPRISE_TYPE,$ID,$newID);
					}
					// Founded -> use to link : nothing to do
				}	
				return $newID;	
			}
		} else {
			return 0;
		}
	}

	/**
	* Transfer contacts of an enterprise
	*
	*@param $ID original ID of the enterprise
	*@param $newID new ID of the enterprise
	**/
	function transferEnterpriseContacts($ID,$newID){
		global $DB;
		$need_clean_process=false;
		// if keep 
		if ($this->options['keep_contacts']){
			$contact=new Contact();
			// Get contracts for the item
			$query="SELECT * FROM glpi_contacts_suppliers" .
					" WHERE suppliers_id = '$ID' AND contacts_id NOT IN " . $this->item_recurs[CONTACT_TYPE];
			if ($result = $DB->query($query)) {
				if ($DB->numrows($result)>0) { 
					// Foreach get item 
					while ($data=$DB->fetch_array($result)) {
						$need_clean_process=false;
						$item_ID=$data['contacts_id'];
						$newcontactID=-1;
						// is already transfer ?
						if (isset($this->already_transfer[CONTACT_TYPE][$item_ID])){
							$newcontactID=$this->already_transfer[CONTACT_TYPE][$item_ID];
							if ($newcontactID!=$item_ID){
								$need_clean_process=true;
							}
						} else {
							$canbetransfer=true;
							// Transfer enterprise : is the contact used for another enterprise ?
							if ($ID==$newID){
								$query_search="SELECT count(*) AS CPT " .
										" FROM glpi_contacts_suppliers" .
										" WHERE contacts_id='$item_ID' " .
										" AND suppliers_id NOT IN ".$this->item_search[ENTERPRISE_TYPE] .
										" AND suppliers_id NOT IN ".$this->item_recurs[ENTERPRISE_TYPE];
								$result_search = $DB->query($query_search);
								if ($DB->result($result_search,0,'CPT')>0){
									$canbetransfer=false;
								}
							}

							// Yes : transfer 
							if ($canbetransfer){
								$this->transferItem(CONTACT_TYPE,$item_ID,$item_ID);
								$newcontactID=$item_ID;
							} else {
								$need_clean_process=true;
								$contact->getFromDB($item_ID);
								// No : search contract
								$query="SELECT * FROM glpi_contacts WHERE entities_id='".$this->to."' AND name='".addslashes($contact->fields['name'])."' AND firstname='".addslashes($contact->fields['firstname'])."'";
								if ($result_search=$DB->query($query)){
									if ($DB->numrows($result_search)>0){
										$newcontactID=$DB->result($result_search,0,'ID');
										$this->addToAlreadyTransfer(CONTACT_TYPE,$item_ID,$newcontactID);
									}
								}
								// found : use it
								// not found : copy contract
								if ($newcontactID<0){
									// 1 - create new item
									unset($contact->fields['ID']);
									$input=$contact->fields;
									$input['entities_id']=$this->to;
									unset($contact->fields);
									$newcontactID=$contact->add($input);
									// 2 - transfer as copy
									$this->transferItem(CONTACT_TYPE,$item_ID,$newcontactID);
								}
							}
						}
						// Update links 
						if ($ID==$newID){
							if ($item_ID!=$newcontactID){
								$query="UPDATE glpi_contacts_suppliers SET contacts_id = '$newcontactID' WHERE ID='".$data['ID']."'";
								$DB->query($query);
							}
							// Same Item -> update links
						} else {
							// Copy Item -> copy links
							if ($item_ID!=$newcontactID){
								$query="INSERT INTO glpi_contacts_suppliers (contacts_id,suppliers_id) VALUES ('$newcontactID','$newID')";
								$DB->query($query);
							} else { // transfer contact but copy enterprise : update link
								$query="UPDATE glpi_contacts_suppliers SET suppliers_id = '$newID' WHERE ID='".$data['ID']."'";
								$DB->query($query);
							}
						}
						// If clean and unused -> 
						if ($need_clean_process&&$this->options['clean_contacts']){
							$query = "SELECT COUNT(*) AS CPT
								FROM glpi_contacts_suppliers 
								WHERE contacts_id='$item_ID'";
							if ($result_remaining=$DB->query($query)){
								if ($DB->result($result_remaining,0,'CPT')==0){
									if ($this->options['clean_contacts']==1){
										$contact->delete(array('ID'=>$item_ID));
									} 
									if ($this->options['clean_contacts']==2) { // purge
										$contact->delete(array('ID'=>$item_ID),1);
									}
								}
							}
						}

					}
				}
			}
		} else {// else unlink
			$query="DELETE FROM glpi_contacts_suppliers WHERE suppliers_id = '$ID'";
			$DB->query($query);
		}

	}

	/**
	* Transfer reservations of an item
	*
	*@param $itemtype original type of transfered item
	*@param $ID original ID of the item
	*@param $newID new ID of the item
	**/
	function transferReservations($itemtype,$ID,$newID){
		global $DB;

		$ri=new ReservationItem();

		if ($ri->getFromDBbyItem($itemtype,$ID)){
			switch ($this->options['keep_reservations']){
				// delete
				case 0 :  
					// Same item -> delete
					if ($ID==$newID){ 
						$ri->delete(array('ID'=>$ri->fields['ID']));
					}
					// Copy : nothing to do
					break;
				// Keep
				case 1 : 
				default : 
					// Copy : set item as reservable
					if ($ID!=$newID){
						$input['itemtype']=$itemtype;
						$input['items_id']=$newID;
						$input['active']=$ri->fields['active'];
						unset($ri->fields);
						$ri->add($input);
					}
					// Same item -> nothing to do
					break;
			}
		}
	}

	/**
	* Transfer devices of a computer
	*
	*@param $itemtype original type of transfered item
	*@param $ID ID of the computer
	*@param $ocs_computer if computer type OCS ID of the item if available
	**/
	function transferDevices($itemtype,$ID,$ocs_computer=false){
		global $DB;
		// Only same case because no duplication of computers
		switch ($this->options['keep_devices']){
			// delete devices
			case 0 :  
				$query = "DELETE FROM glpi_computers_devices 
					WHERE computers_id = '$ID'";
				$result = $DB->query($query);
				// Only case of ocs link update is needed (if devices are keep nothing to do)
				if ($ocs_computer){
					$query="UPDATE glpi_ocslinks SET import_ip = NULL WHERE glpi_id='$ID'";
					$DB->query($query);
				}
				break;
			// Keep devices
			case 1 :	
			default : 
				// Same item -> nothing to do
				break;
		}
	}

	/**
	* Transfer network links
	*
	*@param $itemtype original type of transfered item
	*@param $ID original ID of the item
	*@param $newID new ID of the item
	*@param $ocs_computer if computer type OCS ID of the item if available
	**/
	function transferNetworkLink($itemtype,$ID,$newID,$ocs_computer=false){
		global $DB;
		$np=new Netport();

		$query = "SELECT *
			FROM glpi_networkports 
			WHERE items_id = '$ID' AND itemtype = '$itemtype'";
		if ($result = $DB->query($query)) {
			if ($DB->numrows($result)!=0) { 
				switch ($this->options['keep_networklinks']){
					// Delete netport
					case 0 : 
						// Not a copy -> delete
						if ($ID==$newID){
							while ($data=$DB->fetch_array($result)) {
								$np->delete(array('ID'=>$data['ID']));
							}
							// Only case of ocs link update is needed (if netports are keep nothing to do)
							if ($ocs_computer){
								$query="UPDATE glpi_ocslinks SET import_ip = NULL WHERE glpi_id='$ID'";
								$DB->query($query);
							}
						}
						// Copy -> do nothing
						break;
					// Disconnect
					case 1 : 
						
						// Not a copy -> disconnect
						if ($ID==$newID){ 
							while ($data=$DB->fetch_array($result)) {
								removeConnector($data['ID']);
								if ($data['netpoint']){
									$netpointID=$this->transferDropdownNetpoint($data['netpoint']);
									$input['ID']=$data['ID'];
									$input['netpoint']=$netpointID;
									$np->update($input);
								}
							}
						} else { // Copy -> copy netports
							while ($data=$DB->fetch_array($result)) {
								$data = addslashes_deep($data);
								unset($data['ID']);
								$data['items_id']=$newID;
								$data['netpoint']=$this->transferDropdownNetpoint($data['netpoint']);
								unset($np->fields);
								$np->add($data);
							}
						}
						break;
					// Keep network links 
					case 2 : 
					default : 
						// Copy -> Copy netpoints (do not keep links)
						if ($ID!=$newID){
							while ($data=$DB->fetch_array($result)) {
								unset($data['ID']);
								$data['items_id']=$newID;
								$data['netpoint']=$this->transferDropdownNetpoint($data['netpoint']);
								unset($np->fields);
								$np->add($data);
							}
						} else {
							while ($data=$DB->fetch_array($result)) {
								// Not a copy -> only update netpoint
								if ($data['netpoint']){
									$netpointID=$this->transferDropdownNetpoint($data['netpoint']);
									$input['ID']=$data['ID'];
									$input['netpoint']=$netpointID;
									$np->update($input);
								}
							}
						}

						break;

				}
			}
		}
	}

	/**
	 * Print the transfer form
	 *
	 *
	 * Print transfer form
	 *
	 *@param $target filename : where to go when done.
	 *@param $ID Integer : Id of the contact to print
	 *@param $withtemplate='' boolean : template or basic item
	 *
	 *@return boolean item found
	 *
	 **/
	function showForm ($target,$ID,$withtemplate='') {

		global $CFG_GLPI, $LANG;

		if (!haveRight("transfer","r")) return false;

		$edit_form=true;
		if (!strpos($_SERVER['PHP_SELF'],"transfer.form.php")){
			$edit_form=false;
		}



		if ($ID > 0){
			$this->check($ID,'r');
		} else {
			// Create item 
			$this->check(-1,'w');
			$this->getEmpty();
		} 

		$fctdropdown = (haveRight("transfer","w") ? "dropdownArrayValues" : "dropdownArrayValuesReadOnly");

		echo "<form method='post' name=form action=\"$target\">";
		echo "<div class='center' id='tabsbody' >";
		echo "<table class='tab_cadre_fixe' cellpadding='2' >";
		if ($edit_form){
			echo "<tr><th colspan='4'>";
			if (empty($ID)) {
				echo $LANG['transfer'][2];
			} else {
				echo $LANG['common'][2]." $ID";
			}		
			echo "</th></tr>";
		} else {
				echo "<tr>";
				echo "<td class='tab_bg_2' valign='top' colspan='4'>";
				
				echo "<div class='center'>";
				dropdown("glpi_entities",'to_entity');
				echo "&nbsp;<input type='submit' name='transfer' value=\"".$LANG['buttons'][48]."\" class='submit'></div>";
				echo "</td>";
				echo "</tr>";
		}

      if ($edit_form){
         echo "<tr class='tab_bg_1'>";
         echo "<td colspan='2'>".$LANG['common'][16].":	</td><td colspan='2'>";
         autocompletionTextField("name","glpi_transfers","name",$this->fields["name"],40);
         echo "</td>";
         echo "</tr>";
      }

      $keep=array(0=>$LANG['buttons'][6],
            1=>$LANG['buttons'][49]);
      $clean=array(0=>$LANG['buttons'][49],
         1=>$LANG['buttons'][6],
         2=>$LANG['buttons'][22]);

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['common'][66]." -> ".$LANG['title'][38].":	</td><td>";
      $fctdropdown('keep_history',$keep,$this->fields['keep_history']);
      echo "</td>";
      echo "<td colspan='2'>&nbsp;</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_2'>";
      echo "<td colspan='4' class='center'><strong>".$LANG["Menu"][38]."</strong></td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['common'][66]." -> ".$LANG['networking'][6].":	</td><td>";
      $options=array(0=>$LANG['buttons'][6],
            1=>$LANG['buttons'][49]." - ".$LANG['buttons'][10] ,
            2=>$LANG['buttons'][49]." - ".$LANG['buttons'][9] );
      $fctdropdown('keep_networklinks',$options,$this->fields['keep_networklinks']);
      echo "</td>";
      echo "<td>".$LANG['common'][66]." -> ".$LANG['title'][28].":	</td><td>";
      $options=array(0=>$LANG['buttons'][6],
            1=>$LANG['buttons'][49]." - ".$LANG['buttons'][10] ,
            2=>$LANG['buttons'][49]." - ".$LANG['buttons'][48] );
      $fctdropdown('keep_tickets',$options,$this->fields['keep_tickets']);
      echo "</td>";
      echo "</tr>";



      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG["Menu"][0]." -> ".$LANG["Menu"][4].":	</td><td>";
      $fctdropdown('keep_softwares',$keep,$this->fields['keep_softwares']);
      echo "</td>";
      echo "<td>".$LANG["Menu"][4].": ".$LANG['transfer'][3]."	</td><td>";
      $fctdropdown('clean_softwares',$clean,$this->fields['clean_softwares']);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['common'][66]." -> ".$LANG["Menu"][17].":	</td><td>";
      $fctdropdown('keep_reservations',$keep,$this->fields['keep_reservations']);
      echo "</td>";
      echo "<td>".$LANG["Menu"][0]." -> ".$LANG['title'][30].":	</td><td>";
      $fctdropdown('keep_devices',$keep,$this->fields['keep_devices']);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG["Menu"][2]." -> ".$LANG["Menu"][21]." / ".$LANG['cartridges'][12].":	</td><td>";
      $fctdropdown('keep_cartridges_type',$keep,$this->fields['keep_cartridges_type']);
      echo "</td>";
      echo "<td>".$LANG['cartridges'][12].": ".$LANG['transfer'][3]."	</td><td>";
      $fctdropdown('clean_cartridges_type',$clean,$this->fields['clean_cartridges_type']);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['cartridges'][12]." -> ".$LANG["Menu"][21].":	</td><td>";
      $fctdropdown('keep_cartridges',$keep,$this->fields['keep_cartridges']);
      echo "</td>";
      echo "<td>".$LANG['common'][66]." -> ".$LANG['financial'][3].":	</td><td>";
      $fctdropdown('keep_infocoms',$keep,$this->fields['keep_infocoms']);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['setup'][92]." -> ".$LANG["Menu"][32].":	</td><td>";
      $fctdropdown('keep_consumables',$keep,$this->fields['keep_consumables']);
      echo "</td>";
      echo "<td colspan='2'>&nbsp;</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_2'>";
      echo "<td colspan='4' class='center'><strong>".$LANG['connect'][0]."</strong></td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG["Menu"][3].":	</td><td>";
      $fctdropdown('keep_dc_monitor',$keep,$this->fields['keep_dc_monitor']);
      echo "</td>";
      echo "<td>".$LANG["Menu"][3].": ".$LANG['transfer'][3]."	</td><td>";
      $fctdropdown('clean_dc_monitor',$clean,$this->fields['clean_dc_monitor']);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG["Menu"][2].":	</td><td>";
      $fctdropdown('keep_dc_printer',$keep,$this->fields['keep_dc_printer']);
      echo "</td>";
      echo "<td>".$LANG["Menu"][2].": ".$LANG['transfer'][3]."	</td><td>";
      $fctdropdown('clean_dc_printer',$clean,$this->fields['clean_dc_printer']);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG["Menu"][16].":	</td><td>";
      $fctdropdown('keep_dc_peripheral',$keep,$this->fields['keep_dc_peripheral']);
      echo "</td>";
      echo "<td>".$LANG["Menu"][16].": ".$LANG['transfer'][3]."	</td><td>";
      $fctdropdown('clean_dc_peripheral',$clean,$this->fields['clean_dc_peripheral']);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG["Menu"][34].":	</td><td>";
      $fctdropdown('keep_dc_phone',$keep,$this->fields['keep_dc_phone']);
      echo "</td>";
      echo "<td>".$LANG["Menu"][34].": ".$LANG['transfer'][3]."	</td><td>";
      $fctdropdown('clean_dc_phone',$clean,$this->fields['clean_dc_phone']);
      echo "</td>";
      echo "</tr>";


      echo "<tr class='tab_bg_2'>";
      echo "<td colspan='4' class='center'><strong>".$LANG["Menu"][26]."</strong></td></tr>";


      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['common'][66]." -> ".$LANG["Menu"][23].":	</td><td>";
      $fctdropdown('keep_enterprises',$keep,$this->fields['keep_enterprises']);
      echo "</td>";
      echo "<td>".$LANG["Menu"][23].": ".$LANG['transfer'][3]."	</td><td>";
      $fctdropdown('clean_enterprises',$clean,$this->fields['clean_enterprises']);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG["Menu"][23]." -> ".$LANG["Menu"][22].":	</td><td>";
      $fctdropdown('keep_contacts',$keep,$this->fields['keep_contacts']);
      echo "</td>";
      echo "<td>".$LANG["Menu"][22].": ".$LANG['transfer'][3]."	</td><td>";
      $fctdropdown('clean_contacts',$clean,$this->fields['clean_contacts']);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['common'][66]." -> ".$LANG["Menu"][27].":	</td><td>";
      $fctdropdown('keep_documents',$keep,$this->fields['keep_documents']);
      echo "</td>";
      echo "<td>".$LANG["Menu"][27].": ".$LANG['transfer'][3]."	</td><td>";
      $fctdropdown('clean_documents',$clean,$this->fields['clean_documents']);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['common'][66]." -> ".$LANG["Menu"][25].":	</td><td>";
      $fctdropdown('keep_contracts',$keep,$this->fields['keep_contracts']);
      echo "</td>";
      echo "<td>".$LANG["Menu"][25].": ".$LANG['transfer'][3]."	</td><td>";
      $fctdropdown('clean_contracts',$clean,$this->fields['clean_contracts']);
      echo "</td>";
      echo "</tr>";


		if (haveRight("transfer","w")) {
			if ($edit_form){
				if ($ID=="") {
					echo "<tr>";
					echo "<td class='tab_bg_2' valign='top' colspan='4'>";
					echo "<div class='center'><input type='submit' name='add' value=\"".$LANG['buttons'][8]."\" class='submit'></div>";
					echo "</td>";
					echo "</tr>";
				} else {
					echo "<tr>";
					echo "<td class='tab_bg_2' valign='top' colspan='2'>";
					echo "<input type='hidden' name='ID' value=\"$ID\">\n";
					echo "<div class='center'><input type='submit' name='update' value=\"".$LANG['buttons'][7]."\" class='submit' ></div>";
					echo "</td>\n\n";
					echo "<td class='tab_bg_2' valign='top' colspan='2'>\n";
					echo "<div class='center'><input type='submit' name='delete' value=\"".$LANG['buttons'][6]."\" class='submit'></div>";
					echo "</td>";
					echo "</tr>";

				}
			} 
		}
		echo "</table></div></form>";
		echo "<div id='tabcontent'></div>";
		//echo "<script type='text/javascript'>loadDefaultTab();</script>";
		return true;
	}
	/// Display items to transfers
	function showTransferList(){
		global $LANG,$LINK_ID_TABLE,$DB,$CFG_GLPI;
		$ci=new CommonItem();
		if (isset($_SESSION['glpitransfer_list'])&&count($_SESSION['glpitransfer_list'])){
			echo "<div class='center'><strong>".$LANG['transfer'][5]."<br>".$LANG['transfer'][6];
			echo "</strong>";
			echo "</div>";
			//echo '<tr><th colspan="2">'.$LANG['transfer'][4].'</th></tr>';
			echo "<table class='tab_cadre_fixe' >";
			echo '<tr><th>'.$LANG['transfer'][7].'</th><th>'.$LANG['transfer'][8].":&nbsp;";
			$rand=dropdownValue("glpi_transfers","ID",0,0,-1,array('value_fieldname'=>'ID',
			'to_update'=>"transfer_form", 'url'=>$CFG_GLPI["root_doc"]."/ajax/transfers.php"));
			echo '</th></tr>';
			echo "<tr><td class='tab_bg_1' valign='top'>";
			
			foreach ($_SESSION['glpitransfer_list'] as $itemtype => $tab){
				if (count($tab)){
					$table=$LINK_ID_TABLE[$itemtype];
					$query="SELECT $table.ID, $table.name, glpi_entities.completename AS locname, glpi_entities.ID AS entID 
						FROM $table LEFT JOIN glpi_entities ON ($table.entities_id = glpi_entities.ID) 
						WHERE $table.ID IN ".$this->createSearchConditionUsingArray($tab)."
						ORDER BY locname, $table.name";
					$entID=-1;
					if ($result=$DB->query($query)){
						if ($DB->numrows($result)){
							$ci->setType($itemtype);
							echo '<h3>'.$ci->getType().'</h3>';
							while ($data=$DB->fetch_assoc($result)){
								if ($entID!=$data['entID']){
									if ($entID!=-1){
										echo '<br>';
									}
									$entID=$data['entID'];
									if ($entID>0){
										echo '<strong>'.$data['locname'].'</strong><br>';
									} else {
										echo '<strong>'.$LANG['entity'][2].'</strong><br>';
									}
								}
								echo ($data['name'] ? $data['name']."<br>" : "(".$data['ID'].")<br>");
							}
						}
					}
				}
			}
			echo "</td><td class='tab_bg_2' valign='top'>";
			if (countElementsInTable('glpi_transfers')==0){
				echo $LANG['search'][15];
			} else {
				
				$params=array('ID'=>'__VALUE__');
				ajaxUpdateItemOnSelectEvent("dropdown_ID$rand","transfer_form",$CFG_GLPI["root_doc"]."/ajax/transfers.php",$params,false);
				//ajaxUpdateItem("transfer_form",$CFG_GLPI["root_doc"]."/ajax/transfers.php",$params,false,"dropdown_ID".$rand);
			}
			echo "<div align='center' id='transfer_form'>";
			echo "<a href='".$_SERVER['PHP_SELF']."?clear=1'>".$LANG['transfer'][4]."</a>";
			echo "</div>";
			echo '</td></tr>';
			echo '</table>';
		} else {
			echo $LANG['common'][24];
		}

	}
}


?>
