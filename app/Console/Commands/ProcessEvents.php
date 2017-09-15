<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Event;
use App\State;

class ProcessEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alerter:process-events';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process recent events and populate states table.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
		$this->process_events();
		//$this->update_state("balls2", 1);
    }

	public function process_events()
	{
		$events = Event::where('processed', 0)->where('type', "device")->get();

		if($events->isNotEmpty())
		{
			print $events->count() . " EVENTS TO PROCESS!\n";
			foreach($events as $event)
			{
				print "Processing event for device " . $event->name . "...\n";
				$state = State::updateOrCreate(
					['name' => $event->name, 'type' => $event->type],
					['state' => $event->state,'processed' => 0]
				);
				if($state)
				{
					$event->processed = 1;
					$event->save();
				}
			}
		} else {
			print "NO EVENTS TO PROCESS!\n";
		}

	}


}
