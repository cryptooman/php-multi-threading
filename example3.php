<?php

/**
 * Parent and threads exchange value example.
 * Thread value shares with other threads and parent. 
 */

require_once("./Thread.php");
require_once("./ThreadException.php");

class TestThread extends Thread
{
    public $name;
    
    function __construct($threadName) {
        $this->name = $threadName;
        parent::__construct();
    }

    protected function _run()
    {
        for($i = 0; $i < 5; $i++) {
            $counter = $this->getShared('counter', true);
            $counter += 0.01;	
            $this->setShared('counter', $counter);
            	
            echo "Thread [$this->name] incremented counter to $counter\n";	
			sleep(1);
		}
		
		return "thread [$this->name] counted to $counter";
    }
}

try {
    $threadCount    = 5;
    $counter        = 0;
    
    for($i = 0; $i < $threadCount; $i++) {
        $thread[$i] = new TestThread("t-$i");
    }

    $thread[0]->setShared('counter', 0);
    
    for($i = 0; $i < $threadCount; $i++) {
        $thread[$i]->run();
    }
    
    while(1) {
        if (!Thread::hasActive()) {
            break;
        }

        $counter = $thread[0]->getShared('counter', true);
        $counter += 1;
        $thread[0]->setShared('counter', $counter);
				
        echo "Parent incremented counter to $counter\n";
			
        sleep(1);
    }

    for($i = 0; $i < $threadCount; $i++) {
        echo "thread [t-$i] return: \"".$thread[$i]->retval()."\"\n";
    }
    
    Thread::finalize();     // NOTE: Don't forget to free resources!
}
catch(Exception $e) {
    echo $e->getMessage()."\n";
}