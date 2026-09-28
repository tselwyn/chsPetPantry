<?php
/*
 * Copyright 2013 by Allen Tucker. 
 * This program is part of RMHC-Homebase, which is free software.  It comes with 
 * absolutely no warranty. You can redistribute and/or modify it under the terms 
 * of the GNU General Public License as published by the Free Software Foundation
 * (see <http://www.gnu.org/licenses/ for more information).
 * 
 */

/*
 * Created on Mar 28, 2008
 * @author Oliver Radwan <oradwan@bowdoin.edu>, Sam Roberts, Allen Tucker
 * @version 3/28/2008, revised 7/1/2015
 */


class Person {
	
	private $personId; // unique number
	private $id; // username
	private $first_name;
	private $last_name;
	private $email;
	private $type; // admin, inventory counter
	private $status; // active, inactive, deleted
	private $password;
	
	function __construct(
        $personId, $id, $first_name, $last_name, $email, $type, $status, $password) {
		$this->personId = $personId;
        $this->id = $id;
		$this->first_name = $first_name;
		$this->last_name = $last_name;
		$this->email = $email;
		$this->type = $type;
		$this->status = $status;
		$this->password = $password;
    }

	function get_personId() {
		return $this->personId;
	}

	function get_id() {
		return $this->id;
	}

	function get_first_name() {
		return $this->first_name;
	}

	function get_last_name() {
		return $this->last_name;
	}

	function get_email() {
		return $this->email;
	}

	function get_type() {
		return $this->type;
	}

	function get_status() {
		return $this->status;
	}

	function get_password() {
		return $this->password;
	}

	function get_access_level() {
		if (strtolower($this->type) == 'superadmin') {
    		$access = 3;
		} else if (strtolower($this->type) == 'admin') {
    		$access = 2;
		} else {
    		$access = 1;
		}
		return $access;
	}

	public function __toString(){
        return "PersonId: {$this->personId}, id: {$this->id}, First Name: {$this->first_name}, Last Name: {$this->last_name}, 
				Email: {$this->email}, Type: {$this->type}, Status: {$this->status}";
    }

}