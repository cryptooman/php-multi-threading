<?php

/**
 * Simple thread example.
 * Processing threads and return values to a parent.
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
            echo "thread [$this->name] count $i\n";
            sleep(1);
        }
        return "thread [$this->name] counted to $i";
    }
}

try {
    $threadCount = 5;

    for($i = 0; $i < $threadCount; $i++) {
        $thread[$i] = new TestThread("t-$i");
        $thread[$i]->run();
    }

    Thread::wait();

    for($i = 0; $i < $threadCount; $i++) {
        echo "thread [".$thread[$i]->name."] return: \"".$thread[$i]->retval()."\"\n";
    }
}
catch(Exception $e) {
    echo $e->getMessage()."\n";
}