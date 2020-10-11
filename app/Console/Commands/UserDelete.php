<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Notifications\TrainingClosedNotification;
use App\User;
use App\Handover;
use App\Training;

class UserDelete extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:delete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Wipe or pseudonymize a user';

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
     * Close trainings of user to remove them from queue if applicable
     * 
     */
    public function closeUserTrainings($user){
        
        $trainings = Training::where('user_id', $user->id)->where('status', '>=', 0)->get();
        foreach($trainings as $training){

            // Training should be closed
            $training->updateStatus(-4);

            // Notify the student
            $training->user->notify(new TrainingClosedNotification($training, -4, 'Closed due to data deletion request.'));

        }

    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $cid = $this->ask("What is the user's CID?");

        if($user = User::find($cid)){
            $userInfo = $user->name." (".$cid.")";
            
            $this->comment($userInfo." found in records of Control Center and Handover!");

            $choices = [
                "PSEUDONYMISE -> Used for GDPR deletion requests from user directly to us",
                "PERMANENTLY DELETE -> Deletes all data related to this user, only applicable if user is banned from VATSIM and will never return",
            ];
            $choice = $this->choice('Do you want to PSEUDONYMISE or DELETE the user?', $choices);
            
            // PSEUDONYMISE
            if($choice == $choices[0]){
                $confirmed = $this->confirm("Are you sure you want to PSEUDONYMISE ".$userInfo."?");
                if($confirmed){

                    // Remove things from Control Center
                    $this->closeUserTrainings($user);
                    $user->country = null;
                    $user->group = null;
                    $user->remember_token = null;
                    $user->save();

                    // Psuedonymise in Handover
                    $handover = Handover::find($cid);
                    $handover->email = "void@void.void";
                    $handover->first_name = "Anonymous";
                    $handover->last_name = "Anonymous";
                    $handover->country = null;
                    $handover->region = "XXX";
                    $handover->division = null;
                    $handover->subdivision = null;
                    $handover->atc_active = null;
                    $handover->visiting_controller = null;
                    $handover->accepted_privacy = false;
                    $handover->remember_token = null;
                    $handover->access_token = null;
                    $handover->refresh_token = null;
                    $handover->token_expires = null;
                    $handover->save();

                    $this->comment($userInfo." has been pseudoymised in Control Center and Handover. This will be reverted IF they log into Handover again.");
                }
            // PERMANENTLY DELETE
            } elseif($choice == $choices[1]){
                $confirmed = $this->confirm("Are you sure you want to PERMANENTLY DELETE ".$userInfo."? This is IRREVERSIBLE!");
                if($confirmed){

                    // Remove things from Control Center
                    $user->delete();

                    // Delete from Handover
                    $handover = Handover::find($cid)->delete();

                    $this->comment("All data related to ".$userInfo." has been permanently deleted from Control Center and Handover!");
                }
            }

        } else {
            $this->error("No records of ".$cid." was found.");
        }

    }
}