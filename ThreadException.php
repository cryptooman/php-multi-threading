<?php

/**
 * Thread exception.
 *
 */
class ThreadException extends Exception
{
    /**
     * Thread exception constructor.
     *
     * @param string $err. Exception message.
     * @param Thread $thread. Thread instance.
     */
	function __construct($err, Thread $thread=null)
	{
		if($thread) {
			$thread->stop();
		}
		else { 
			Thread::finalize();
		}
			
		parent::__construct($err);
	}
}