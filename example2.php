<?php

/**
 * Parent and threads exchange values example.
 * Thread value doesn't share with other threads, just with parent. 
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
        while(1) {
            $counter = $this->get('counter');
            $counter += 0.01;
            $this->set('counter', $counter);
			
            echo "thread [$this->name] increment it's counter to $counter\n";
			
            if ($counter > 5) {
                break;
            }
            
            sleep(1);
		}
		
		return "thread [$this->name] counted to $counter";
    }
}

try {
    $threadCount = 5;

    for($i = 0; $i < $threadCount; $i++) {
        $thread[$i] = new TestThread("t-$i");
        $thread[$i]->set('counter', 0);
        $thread[$i]->run();
    }

    while(1) {
        if (!Thread::hasActive()) {
            break;
        }
        
        for($i = 0; $i < $threadCount; $i++) {
            $counter = $thread[$i]->get('counter');
            $counter += 1;
            $thread[$i]->set('counter', $counter);
				
            echo "Parent increment thread's [".$thread[$i]->name."] counter to $counter\n";
        }
        
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