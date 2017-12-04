<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Ticket;
use App\State;
use App\ServiceNowIncident;
use App\ServiceNowLocation;
use Carbon\Carbon;
use GuzzleHttp\Client as GuzzleHttpClient;

class Incident extends Model
{
	use SoftDeletes;
	protected $fillable = ['name','type'];

	protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
		'last_opened',
		'called_oncall',
		'called_sup'
    ];

	//close this incident
	public function close()
	{
		$this->resolved = 1;
		$this->save(); 
	}

	//open this incident
	public function open()
	{
		//$this->last_opened = Carbon::now();
		$this->resolved = 0;
		$this->save();
	}
	
	public function isOpen()
	{
		if ($this->resolved == 1 || $this->trashed())
		{
			return false;
		} else {
			return true;
		}
	}
	
	public function purge()
	{
		$states = $this->get_states();
		//Delete all states associated to this internal incident
		foreach($states as $state)
		{
			$state->delete();
		}
		//DELETE this internal incident from database.
		$this->delete();
	}
	
	public static function isAfterHours()
	{
		$start = Carbon::createFromTime(env("TIME_WORKDAY_START"),0,0,env("TIME_ZONE"));
		$end = Carbon::createFromTime(env("TIME_WORKDAY_END"),0,0,env("TIME_ZONE")); 
		$now = Carbon::now(env("TIME_ZONE"));
		if($now->isWeekday())
		{
			if($now->between($start, $end))
			{
				return false;
			}
		}
		return true;
	}
	
	public function nameToVoice()
	{
		return implode(" ", str_split($this->name));	
	}
	
	public function getUrgency()
	{
		$location = $this->get_location();
		if($this->type == "site")
		{
			if($location)
			{
				if($location->u_priority == 2)
				{
					$urgency = 1;			
				} else {
					$urgency = 2;
				}
			} else {
				$urgency = 2;
			}
		} else {
			$urgency = 3;
		}
		return $urgency;
	}
	
	public function get_location()
	{
		//grab the first 8 characters of our name.  This is our sitecode!
		$sitecode = strtoupper(substr($this->name,0,8));
		$location = null;
		try
		{
			$location = ServiceNowLocation::where("name","=",$sitecode)->first();
		} catch(\Exception $e) {
		
		}
		return $location;
	}

	public function create_ticket_description()
	{
		$description = "";
		if($this->type == "site")
		{
			$description .= "The following devices are in an ALERT state at site " . strtoupper($this->name) . ": \n";
			foreach($this->get_states() as $state)
			{
				$description .= $state->name . "\n";
			}
		}
		if($this->type == "device")
		{
			$description .= "The following device is in an ALERT state : \n";
			$description .= strtoupper($this->name) . "\n";
		}

		$description .= "\n";
		$location = $this->get_location();
		if ($location)
		{
			$description .= "*****************************************************\n";
			$description .= "SITE NAME: " . $location->name . "\n\n";
			$description .= "Display Name: " . $location->u_display_name . "\n\n";
			$description .= "Description: " . $location->description . "\n\n";
			$description .= "Address: " . $location->street . ", " . $location->city . ", " . $location->state . ", " . $location->zip . "\n\n";
			$description .= "Comments: \n" . $location->u_comments . "\n\n";

			$contact = $location->getContact();
			if($contact)
			{
				$description .= "*****************************************************\n";
				$description .= "Site Contact: \nName: " . $contact->name . "\nPhone: " . $contact->phone . "\n";			
			}
			$description .= "*****************************************************\n";
			if($location->u_priority == 0)
			{
				$description .= "Site Priority: NO MONITORING!\n";
			} elseif ($location->u_priority == 1) {
				$description .= "Site Priority: NEXT BUSINESS DAY\n";
			} elseif ($location->u_priority == 2) {
				$description .= "Site Priority: 24/7\n";
			}
			$opengear = $location->getOpengear();
			$description .= "*****************************************************\n";
			if($opengear)
			{
				$description .= "Opengear " . strtoupper($location->name) . "OOB01 status: " . $opengear . "\n";
			} else {
				$description .= "Opengear " . strtoupper($location->name) . "OOB01 does NOT exist!\n";
			}
			$weatherdesc = $location->getWeather();
			if($weatherdesc)
			{
			$description .= "*****************************************************\n";
			$description .= "Weather Information : " . $weatherdesc . "\n";
			}
		} else {
			$description .= 'Location "' . strtoupper(substr($this->name,0,8)) . '" not found!';
		}
		$description .= "*****************************************************\n";
		return $description;
	}

	public function createNewTicket()
	{
		$urgency = $this->getUrgency();
		if($this->type == "site")
		{
			$summary = "Multiple devices down at site " . strtoupper($this->name);
		}
		if($this->type == "device")
		{
			$summary = "Device " . strtoupper($this->name) . " is down!";
		}
		$ticket = $this->createTicket($urgency, $summary);
		return $ticket;
	}

	public function createTicket($urgency, $summary)
	{
		$description = $this->create_ticket_description();
		print "Creating Ticket of type " . $this->type . "\n";
		$ticket = ServiceNowIncident::create([
			"cmdb_ci"			=>	env('SNOW_cmdb_ci'),
			"impact"			=>	2,
			"urgency"			=>	$urgency,
			"short_description"	=>	$summary,
			"description"		=>	$description,
			"assigned_to"		=>	"",
			"caller_id"			=>	env('SNOW_caller_id'),
			"assignment_group"	=>	env('SNOW_assignment_group'),
		]);
		if($ticket)
		{
			$this->ticket = $ticket->sys_id;
			$this->last_opened = Carbon::now();
			$this->save();
			if($urgency == 1)
			{
				if($this->isAfterHours())
				{
					$msg = "A High priority incident has been opened." . $ticket->numberToVoice() . ", Multiple devices are down at site " . $this->nameToVoice();
					$this->callOncall($msg);
				}
			}
			return $ticket;
		}
		return null;
	}

	public function reopenTicket()
	{
		$ticket = $this->get_ticket();
		$unstates = $this->get_unresolved_states();
		if($ticket)
		{
			//COMMENT SNOW TICKET
			$msg = "The following devices have entered an alert state: \n";
			//REOPEN INCIDENT AND SNOW TICKET
			foreach($unstates as $unstate)
			{
				$msg .= $unstate->name . "\n";
				$unstate->processed = 1;
				$unstate->save();
			}
			$msg .= "\nReopening the ticket!";
			$ticket->add_comment($msg);
			$this->resolved = 0;
			$this->last_opened = Carbon::now();
			$this->called_oncall = null;
			$this->called_sup = null;
			$this->save();
			$ticket->urgency = $this->getUrgency();
			$ticket->impact = 2;
			$ticket->assigned_to = "";
			$ticket->state=2;
			$ticket->save();
			$ticketnumber = implode(" ", str_split($ticket->number));
			$sitename = implode(" ", str_split($this->name));
			if($this->isAfterHours())
			{
				if($ticket->priority == 1 || $ticket->priority == 2)
				{
					$msg = "A " . $ticket->getPriorityString() . " priority incident has been reopened.  Ticket Number " . $ticket->numberToVoice() . "," . $ticket->short_description . ", Site Code " . $this->nameToVoice();
					$this->callOncall($msg);
				}
			}
			return true;
		}
		return false;
	}
	
	public function autoCloseTicket()
	{
		$ticket = $this->get_ticket();
		$msg = "All devices have recovered.  Auto Closing Ticket!";
		print $this->name . " " . $msg . "\n";
		$ticket->add_comment($msg);
		print "CLOSE TICKET : " . $this->name . "\n";
		$ticket->close($msg);
		$this->close();
	}
	
	public function get_states()
	{
		return State::where('incident_id', $this->id)->get();
	}
	
	public function get_latest_state()
	{
		$states = $this->get_states();
		$neweststate = $states->first();
		foreach($states as $state)
		{
			if($state->updated_at->gt($neweststate->updated_at))
			{
				$neweststate = $state;
			}
		}
		return $neweststate;
	}
	
	public function updateTicket()
	{
		$msg = "";
		if($ticket = $this->get_ticket())
		{
			$ustates = $this->get_unresolved_states();
			$rstates = $this->get_resolved_states();
			$msg.= "State update detected.  Current status:\n";
			if($ustates->isNotEmpty())
			{
				$msg .= "The following states are in an ALERT state: \n";
				foreach($ustates as $ustate)
				{
					$msg .= $ustate->name . "\n";
					$ustate->processed = 1;
					$ustate->save();
				}
			}
			if($rstates->isNotEmpty())
			{
				$msg .= "\nThe following states are in a RECOVERED state: \n";
				foreach($rstates as $rstate)
				{
					$msg .= $rstate->name . "\n";
					$rstate->processed = 1;
					$rstate->save();
				}
			}
			$ticket->add_comment($msg);
			return 1;
		}
		return null;
	}

	public function callOncall($msg)
	{
		$this->callVoice(env("TROPO_ONCALL_NUMBER"),$msg);
		$this->save();
	}

	public function escalateOncall($msg)
	{
		$this->callVoice(env("TROPO_ONCALL_NUMBER"),$msg);
		$this->called_oncall = Carbon::now();
		$this->save();
	}

	public function escalateSup($msg)
	{
		$this->callVoice(env("TROPO_SUP_NUMBER"),$msg);
		$this->called_sup = Carbon::now();
		$this->save();
	}

	public function callVoice($number, $msg)
	{
		$paramsarray = [
			"token"		=>	env("TROPO_TOKEN"),
			"from"		=>	env("TROPO_CALLERID"),
			"msg"		=>	$msg,
			"number"	=>	$number,
		];
		$params['body'] = json_encode($paramsarray);
		$params['headers'] = [
			'Content-Type'	=> 'application/json',
			'Accept'		=> 'application/json',
		];
		$client = new GuzzleHttpClient;
		try
		{
			$response = $client->request("POST", env("TROPO_BASE_URL"), $params);
		} catch(\Exception $e) {
		
		}
		//get the body contents and decode json into an array.
		$array = json_decode($response->getBody()->getContents(), true);
	}
	
	public function get_unresolved_states()
	{
		return State::where('incident_id', $this->id)->where("resolved",0)->get();
	}
	
	public function get_resolved_states()
	{
		return State::where('incident_id', $this->id)->where("resolved",1)->get();
	}
	
	public function get_unprocessed_states()
	{
		return State::where('incident_id', $this->id)->where("processed",0)->get();
	}
	
	public function get_ticket()
	{
		if ($this->ticket)
		{
			if($ticket = ServiceNowIncident::where("sys_id","=",$this->ticket)->first())
			{
				return $ticket;
			}
		}
		return null;
	}

	public function checkTickets()
	{
		//Fetch me our ticket
		$ticket = $this->get_ticket();
		$states = $this->get_states();
		$unstates = $this->get_unresolved_states();
		$unpstates = $this->get_unprocessed_states();
		
		if($ticket)
		{
			//if the service now ticket is CLOSED (not resolved, but completely closed or cancelled)
			if($ticket->state == 7 || $ticket->state == 4)
			{
				//Purge this incident and all related states.
				$this->purge();
			//If the SNOW ticket is in RESOLVED state
			} elseif ($ticket->state == 6) {
				//IF INCIDENT IS NOT RESOLVED
				if($this->isOpen())
				{
					if($unstates->isEmpty())
					{
						//ALL STATES ARE RESOLVED AND TICKET WAS MANUALLY CLOSED
						$msg = "Manual ticket closure was detected, but all states are resolved anyways.  Clearing " . $this->name . " from Netaas system.\n";
					} else {
						//ALL STATES ARE NOT RESOLVED AND TICKET WAS MANUALLY CLOSED
						$msg = "Manual ticket closure was detected, but all states were NOT resolved.  Clearing " . $this->name . " from Netaas system.\n";
					}
					$msg .= "The following STATES have been removed from the Netaas system: \n";

					//DELETE ALL STATES for this incident
					foreach($states as $state)
					{
						$msg .= $state->name . "\n";
						$state->delete();
					}
					//ADD COMMENT TO TICKET
					$ticket->add_comment($msg);
					//Set incident to RESOLVED
					$this->close();
				//IF INCIDENT IS RESOLVED
				} else {
					//If there are unresolved states, reopen ticket
					if($unstates->isNotEmpty())
					{
						$this->reopenTicket();
					} elseif($this->updated_at->lt(Carbon::now()->subHours(env('TIMER_AUTO_RELEASE_TICKET')))) {
						$ticket->add_comment("This ticket has been in a resolved state for over " . env('TIMER_AUTO_RELEASE_TICKET') . " hours. This ticket is no longer tracked by the Netaas system.");
						$this->purge();
					}
				}
			//If the SNOW ticket is OPEN
			} else {
				//IF INCIDENT IS OPEN
				if($this->isOpen())
				{
					if($unpstates->isNotEmpty())
					{
						$this->updateTicket();
					}
					if($unstates->isEmpty())
					{
						if($this->get_latest_state()->updated_at->lt(Carbon::now()->subMinutes(env('TIMER_AUTO_RESOLVE_TICKET'))))
						{
							$this->autoCloseTicket();
						}
					}
					if($this->isAfterHours())
					{
						if($ticket->priority == 1 || $ticket->priority == 2)
						{
							if($this->last_opened->lt(Carbon::now()->subMinutes(env("TROPO_UNASSIGNED_SUP_ALERT_DELAY"))) && !$ticket->assigned_to && !$this->called_sup)
							{
								$msg = "A " . $ticket->getPriorityString() . " priority incident has been opened for more than " . env("TROPO_UNASSIGNED_SUP_ALERT_DELAY") . " minutes and is currently not assigned.  Ticket Number " . $ticket->numberToVoice() . "," . $ticket->short_description . ", Site Code " . $this->nameToVoice();
								$this->escalateSup($msg);
							}
							if($this->last_opened->lt(Carbon::now()->subMinutes(env("TROPO_UNASSIGNED_ONCALL_ALERT_DELAY"))) && !$ticket->assigned_to && !$this->called_oncall) {
								$msg = "A " . $ticket->getPriorityString() . " priority incident has been opened for more than " . env("TROPO_UNASSIGNED_ONCALL_ALERT_DELAY") . " minutes and is currently not assigned.  Ticket Number " . $ticket->numberToVoice() . "," . $ticket->short_description . ", Site Code " . $this->nameToVoice();
								$this->escalateOncall($msg);
							}
						}
					}
				//IF INCIDENT IS CLOSED
				} else {
					if($unstates->isEmpty())
					{
						$msg = "Ticket was manually re-opened.  Currently there are NO devices in an ALERT state.";
					} else {
						$msg = "Ticket was manually re-opened.  The following devices are currently in an ALERT state: \n";
						foreach($unstates as $unstate)
						{
							$msg .= $unstate->name . "\n";
						}
					}
					$ticket->add_comment($msg);
					$this->open();
				}
			}
		//IF THERE IS NO SNOW TICKET
		} else {
			//IF TYPE IS SITE OR THERE ARE UNRESOLVED STATES
			if($this->type == "site" || $unstates->isNotEmpty())
			{
				//Create a new snow ticket
				print $this->name . " Create SNOW ticket!\n";
				$this->createNewTicket();
			}
		}
	}

	public function process()
	{
		print "Processing Incident " . $this->name . "!!\n";
		$this->checkTickets();
	}
}
