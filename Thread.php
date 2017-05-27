<?php

/**
 * Class provides multi-threading (pseudo multi-threading) abilities.
 *
 * Dependencies:
 *      Linux, Unix
 *      PHP >= 5.2.6
 *
 * @category   PHP
 * @package    System_MultiThreading
 * @author     Dmitry Belyaev <dimarikson@yandex.ru>
 * @version    0.9
 *
 * Example:
 *      <?php
 * 
 *      require_once("./Thread.phps");	
 *      require_once("./ThreadException.phps");
 *
 *      class TestThread extends Thread
 *      {
 *          public $name;
 * 
 *          function __construct($threadName) {
 *              $this->name = $threadName;
 *              parent::__construct();
 *          }
 *          
 *          // Thread logic here
 *          protected function _run()
 *          {
 *              for($i = 0; $i < 5; $i++) {
 *                  echo "thread [$this->name] count $i\n";
 *                  sleep(1);
 *              }
 *              return "thread [$this->name] counted to $i";
 *          }
 *      }
 *
 *      try {
 *          $threadCount = 5;
 *
 *          for($i = 0; $i < $threadCount; $i++) {
 *              $thread[$i] = new TestThread("t-$i");
 *              $thread[$i]->run();
 *          }
 *
 *          Thread::wait();
 *
 *          for($i = 0; $i < $threadCount; $i++) {
 *              echo "thread [".$thread[$i]->name."] return: \"".$thread[$i]->retval()."\"\n";
 *          }
 *      }
 *      catch(Exception $e) {
 *          echo $e->getMessage()."\n";
 *      }
 */
abstract class Thread
{
    /**
     * Max allowed threads to be forked.
     *
     */
    const MAX_THREAD_COUNT          = 50;

    /**
     * Size of each shared memory segment. In kilobytes.
     *
     */
    const SHM_MEMSIZE               = "16K";

    /**
     * Wait delay in loop to check threads execution. In sec.
     *
     */
    const WAIT_DELAY                = 1;		

    /**
     * Prevent from zombie parent process on wait if accidentally it hangs in the system.
     * Tune it to the value that your business task requires. 
     */
    const MAX_WAIT_ATTEMPTS         = 18000;    // (3600*5)

    /**
     * Thread active status.
     *
     */
    const STATUS_ACTIVE             = 1;
    const STATUS_INACTIVE           = 0;

    /**
     * Default shared memory token.
     * Should not be changed.
     */
    const DEFAULT_FTOK              = 1387067009;

    /**
     * Shared memory segments names
     *
     */
    const SHM_SEGMENT_STATUS        = 1;
    const SHM_SEGMENT_PID           = 2;
    const SHM_SEGMENT_RETVAL        = 3;
    const SHM_SEGMENT_THREADVAL     = 4;
    const SHM_SEGMENT_SHAREDVAL     = 5;
	
    /**
     * Specify unique int key if you whant to run several threading scripts at one time.
     *
     * @var int
     */
    protected $_uid;

    /**
     * Indicates whether generic data was instanced
     *
     * @var bool
     */
    private static $_shmInstanced   = false;

    /**
     * Shared memory segments names.
     *
     * @var array
     */
    private static $_shmSegment     = array();

    /**
     * Shared memory access tokens
     *
     * @var array
     */
    private static $_shm            = array();

    /**
     * Semaphores access tokens
     *
     * @var array
     */
    private static $_sem            = array();

    /**
     * Threads return values. 
     *
     * @var array
     */
    private static $_retval         = array();

    /**
     * Amount of forked threads.
     *
     * @var int
     */
    private static $_threadCount    = 0;

    /**
     * Semaphores access indicator.
     *
     * @var bool
     */
    private static $_lock;

    /**
     * Current forked thread id (not pid).
     *
     * @var string (hash).
     */
    private $_threadId;

    /**
     * Instance generic data.
     *
     */
    function __construct()
    {
        if (++self::$_threadCount > self::MAX_THREAD_COUNT) {
            throw new ThreadException("Max allowed threads count [".self::MAX_THREAD_COUNT."] excedeed");
        }

        $this->_threadId = uniqid(true);

		if (!self::$_shmInstanced) {
            $this->_shmInit();
            self::$_shmInstanced = true;
        }
    }

    /**
     * User defined main function logic in child class.
     * Can return thread result value which can be obtained via retval() method. 
     */
    abstract protected function _run();

    /**
     * Wait untill all running threads are executing.
     * Frees the shared memory resorces and reset static class data.
     */
    public static function wait()
    {
        $attempt = 0;

        while(1) {
            sleep(self::WAIT_DELAY);	// Wait delay is placed here to gurantee that all forked threads are yet started

            if (!self::hasActive())
                break;

            if (++$attempt >= self::MAX_WAIT_ATTEMPTS) {
                break;
            }
        }

        if ((self::$_retval = self::_getShmData(self::SHM_SEGMENT_RETVAL)) === false) {
            throw new ThreadException("Failed to get retval");
        }

        self::finalize();
    }

    /**
     * Indicates whether at least one thread is active.
     *
     * @return bool
     */
    public static function hasActive()
    {
        if (($status = self::_getShmData(self::SHM_SEGMENT_STATUS)) === false) {
            throw new ThreadException("No alive threads or shared mem is corrupted");
        }

        if (!in_array(self::STATUS_ACTIVE, array_values($status)) ) {
            return false;
        }

        return true;
    }

    /**
     * Frees the shared memory resources and reset static class data.
     * Normally should be called automatically.
     */
    public static function finalize()
    {
        if ($pidList = self::_getShmData(self::SHM_SEGMENT_PID)) {
            foreach($pidList as $pid) {
                if($pid) {
                    self::_stopByPid($pid);
                }
            }
        }
        
        self::_freeShmResource();

        self::$_threadCount = 0;

        self::$_shmInstanced = null;
    }
    
    /**
     * Forks a new thread (pseudo thread).
     *
     */
    public function run()
    {
        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new ThreadException("Failed to start thread", $this);
        }

        if($pid) {
            try {
                $this->_setPid($pid);

                $this->_setStatus(self::STATUS_ACTIVE);

                $retval = $this->_run();

                $this->_setRetval($retval);
            }
            catch(Exception $e) {
                echo $e->getMessage()."\n";
            }

            try {	
                $this->stop();
            }
            catch(Exception $e) {
                echo $e->getMessage()."\n";
            }

            exit;
        }

        usleep(100000);
    }

    /**
     * Stop the current thread.
     * Normally it should not be called manually.
     */
    public function stop()
    {
        if (!$this->isActive() || !$this->_threadId) {
            return;
        }

        $pid = posix_getpid();

        self::_stopByPid($pid);

        $this->_setPid(null);

        $this->_setStatus(self::STATUS_INACTIVE);

        --self::$_threadCount;
    }

    /**
     * Obtain a thread return value from a shared memory and return it.
     * Can be used only after wait() method is completed the execution.
     *
     * @return mixed. Thread return value
     */
    public function retval()
    {
        if(!self::$_retval)
        {
			if ((self::$_retval = self::_getShmData(self::SHM_SEGMENT_RETVAL)) === false) {
                throw new ThreadException("Failed to get retval");
            }
        }

        if (!$this->_threadId) {
            throw new ThreadException("Unable to get retval of not instanced thread", $this);
        }

        if (!isset(self::$_retval[$this->_threadId])) {
            return null;
        }

        return self::$_retval[$this->_threadId];
    }

    /**
     * Get variable from a thread.
     *
     * @param string $varname. Variable name.
     * @return mixed. Variable value.
     */
    public function get($varname)
    {
        if (($threadval = self::_getShmVar(self::SHM_SEGMENT_THREADVAL, $this->_threadId)) === null) {
            throw new ThreadException("Failed to get threadval [$varname]", $this);
        }

        if (!isset($threadval[$varname])) {
            throw new Error("Undefined threadval [$varname] to get", $this);
        }
        
        return $threadval[$varname];
    }

    /**
     * Set variable to a thread.
     *
     * @param string $varname. Variable name.
     * @param mixed $value. Variable value.
     */
    public function set($varname, $value)
    {
        $threadval = self::_getShmVar(self::SHM_SEGMENT_THREADVAL, $this->_threadId);
        
        $threadval[$varname] = $value;

        $this->_setShmVar(self::SHM_SEGMENT_THREADVAL, $this->_threadId, $threadval);
    }

    /**
     * Get shared variable, i.e. common for parent process and forked threads.
     *
     * @param string $varname. Variable name.
     * @param bool $lock_write. Locks variable read/write and return it to a caller thread.
     * @return mixed. Variable value.
     */
    public function getShared($varname, $lockWrite=false)
    {
        if ($lockWrite) {
            self::_lock(self::SHM_SEGMENT_SHAREDVAL);
        }

        if (($sharedval = self::_getShmVar(self::SHM_SEGMENT_SHAREDVAL, $varname)) === null) {
            throw new Error("Undefined sharedval [$varname] to get", $this);
        }

        return $sharedval;
    }

    /**
     * Set shared variable, i.e. common for parent process and forked threads.
     *
     * @param string $varname. Variable name. 
     * @param mixed $value. Variable value.
     */
    public function setShared($varname, $value)
    {
        $this->_setShmVar(self::SHM_SEGMENT_SHAREDVAL, $varname, $value);
    }

    /**
     * Checks whether thread is active.
     *
     * @return bool.
     */
    public function isActive()
    {
        if (!self::$_shmInstanced) {
            return false;
        }

        $status = self::_getShmVar(self::SHM_SEGMENT_STATUS, $this->_threadId);

        return (bool) $status;
    }
	
    /**
     * Frees the shared memory resources.
     *
     */
    private static function _freeShmResource()
    {
        if (!empty(self::$_shmSegment)) {
            foreach(self::$_shmSegment as $segment) {
                if (isset(self::$_shm[$segment]) && is_int(self::$_shm[$segment])) {
                    shm_put_var(self::$_shm[$segment], $segment, null);     // shm_remove() doesn't delete data in shared memory, so it must be set to null
                    shm_remove(self::$_shm[$segment]);
                }

                if (isset(self::$_sem[$segment]) && is_resource(self::$_sem[$segment])) {
                    @sem_remove(self::$_sem[$segment]);
                }
            }
        }

        self::$_shm = array();
        self::$_sem = array();
    }

    /**
     * Get the data from a shared memory segment.
     *
     * @param string $segment. Shared memory segment name.
     * @return string. Shared memory data. 
     */
    private static function _getShmData($segment)
    {
        $attempt = 3;

        while(--$attempt > 0) {
            $data = @shm_get_var(self::$_shm[$segment], $segment);

            if(is_array($data))
            return $data;
	
            usleep(10000);
        }

        return false;
    }

    /**
     * Get variable value from a shared memory by segment name and a thread id.
     *
     * @param string $segment. Shared memory segment name.
     * @param string $varname. Variable name to get.
     * @return mixed. Variable value.
     */
    private static function _getShmVar($segment, $varname)
    {
        if (!self::$_shmInstanced) {
            throw new ThreadException("Unable to get var from not instanced shared memory");
        }

        if (($data = self::_getShmData($segment)) === false) {
            return null;
        }
		
        if (!isset($data[$varname])) {
            return null;
        }
	
        return $data[$varname];
    }

    /**
     * Set variable value to a shared memory by segment name and a thread id.
     *
     * @param string $segment. Shared memory segment name.
     * @param string $varname. Variable name to set.
     * @param mixed $value. Variable value.
     */
    private static function _setShmVar($segment, $varname, $value)
    {
        if (!self::$_shmInstanced) {
            throw new ThreadException("Unable to set var to not instanced shared memory");
        }

        self::_lock($segment);
		
        if (($data = self::_getShmData($segment)) === false) {
            throw new ThreadException("Failed to get shared mem data for set var for segment [$segment]", $this);
        }	
	
        $data[$varname] = $value;

        if (!shm_put_var(self::$_shm[$segment], $segment, $data)) {
            throw new ThreadException("Failed to set shared mem data for segment [$segment]", $this);
        }	
	
        self::_unlock($segment);
    }

    /**
     * Locks shared memory segment (acquire a semaphore).
     *
     * @param string $segment. Shared memory segment name.
     */
    private static function _lock($segment)
    {
        if (!self::$_lock[$segment]) {

            self::$_lock[$segment] = true;

            if (!sem_acquire(self::$_sem[$segment])) {
                throw new ThreadException("Failed to lock segment [$segment]", $this);
            }
        }
    }

    /**
     * Unlocks shared memory segment (release a semaphore).
     *
     * @param string $segment. Shared memory segment name.
     */
    private static function _unlock($segment)
    {
        if (!sem_release(self::$_sem[$segment])) {
            throw new ThreadException("Failed to unlock segment [$segment]", $this);
        }

        self::$_lock[$segment] = false;
    }

    /**
     * Stop process (thread) by its pid.
     *
     * @param int $pid. Pid of a process.
     * @return bool.
     */
    private static function _stopByPid($pid)
    {
        if (!posix_kill($pid, SIGTERM)) {
            return false;
        }

        pcntl_waitpid($pid, $status);

        if (!pcntl_wifexited($status)) {
            return false;
        }

        return true;
    }

    /**
     * Initialize the shared memory segments and get semaphores access key.
     *
     */
    private function _shmInit()
    {
        self::$_shmSegment = array(
            self::SHM_SEGMENT_STATUS, 
            self::SHM_SEGMENT_PID, 
            self::SHM_SEGMENT_RETVAL, 
            self::SHM_SEGMENT_THREADVAL, 
            self::SHM_SEGMENT_SHAREDVAL
        );

        self::_freeShmResource();

        $memsize = (int) self::SHM_MEMSIZE * 1024;

        $uid = $this->_uid ? $this->_uid : self::DEFAULT_FTOK;

        foreach(self::$_shmSegment as $i => $shm) {
            $ftok[$shm] 	= $uid + $i;
            $semkey[$shm] 	= $uid + 100 + $i;
        }

        if (!pcntl_signal(SIGTERM, array($this, "_sigHandler")) ) {
            throw new ThreadException("Failed to register pcntl signal");
        }
	
        foreach(self::$_shmSegment as $shm) {
            if (!self::$_shm[$shm] = shm_attach($ftok[$shm], $memsize)) {
                throw new ThreadException("Failed to init shared mem for segment [$shm]");
            }

            if (!shm_put_var(self::$_shm[$shm], $shm, array()) ) {
                throw new ThreadException("Failed to init shared mem data for segment [$shm]");
            }
	
            if (!self::$_sem[$shm] = sem_get($semkey[$shm], 1, 0600)) {
                throw new ThreadException("Failed to get semaphore id for segment [$shm]");
            }
            
            self::$_lock[$shm] = false;
        }

        self::$_retval = array();
    }
		
    /**
     * Set thread status to shared memory.
     *
     * @param int $status. Thread status.
     */
    private function _setStatus($status)
    {
        self::_setShmVar(self::SHM_SEGMENT_STATUS, $this->_threadId, $status);
    }

    /**
     * Set thread pid to shared memory.
     *
     * @param int $pid. Thread pid.
     */
    private function _setPid($pid)
    {
        self::_setShmVar(self::SHM_SEGMENT_PID, $this->_threadId, $pid);
    }

    /**
     * Set thread return value to shared memory.
     *
     * @param mixed $retval. Thread return value.
     */
    private function _setRetval($retval)
    {
        self::_setShmVar(self::SHM_SEGMENT_RETVAL, $this->_threadId, $retval);
    }
    
    /**
     * Signal handler.
     *
     * @param int $signo. Signal number.
     */
    private function _sigHandler($signo)
    {
        if($signo == SIGTERM) {
            exit;
        }
    }
}