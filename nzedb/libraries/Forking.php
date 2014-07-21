<?php
namespace nzedb\libraries;

require_once(nZEDb_LIBS . 'forkdaemon-php' . DS . 'fork_daemon.php');

/**
 * Class Forking
 *
 * @package nzedb\libraries
 */
class Forking extends \fork_daemon
{
	/**
	 * Setup the class to work on a type of work, then process the work.
	 * Valid work types:
	 *
	 * @param string $type    The type of multiProcessing to do : backfill, binaries, releases, postprocess
	 * @param array  $options Array containing arguments for the type of work.
	 *
	 * @throws ForkingException
	 */
	public function processWorkType($type, array $options = array())
	{
		$time = microtime(true);
		// Init Settings here, as forking causes errors when the class goes out of scope.
		$this->pdo = new \nzedb\db\Settings();
		$this->getWork($type, $options);
		// Now we destroy settings, to prevent errors from forking.
		unset($this->pdo);
		$this->processWork();
		if (nZEDb_ECHOCLI) {
			echo 'Multiprocessing for ' . $type . ' finished in ' . (microtime(true) - $time) . ' seconds.' . PHP_EOL;
		}
	}

	/**
	 *
	 */
	public function __construct()
	{
		parent::__construct();
		$this->register_logging(
			[0 => $this, 1 => 'logger'],
			(nZEDb_DEBUG === true ? \fork_daemon::LOG_LEVEL_ALL : \fork_daemon::LOG_LEVEL_INFO)
		);
		$this->max_children_set(3);
		$this->max_work_per_child_set(1);
		$this->child_max_run_time_set(600);
		$this->register_parent_child_exit([0 => $this, 1 => 'childExit']);
	}

	/**
	 * @var array
	 */
	private $work = array();

	/**
	 * Get work for our workers to work on.
	 *
	 * @param string $type
	 * @param array  $options
	 */
	private function getWork(&$type, array &$options = array())
	{
		$maxProcesses = 0;
		$this->processAdditional = $this->processNFO = $this->processTV = $this->processMovies = false;
		$this->work = array();
		switch ($type) {
			// The option for backFill is for doing up to x articles. Else it's done by date.
			case 'backfill':
				$this->register_child_run([0 => $this, 1 => 'backFillChildWorker']);
				$this->work = $this->pdo->query(
					sprintf(
						'SELECT name %s FROM groups WHERE backfill = 1',
						($options[0] === false ? '' : (', ' . $options[0] . ' AS max'))
					)
				);
				$maxProcesses = $this->pdo->getSetting('backfillthreads');
				break;

			case 'binaries':
				$this->register_child_run([0 => $this, 1 => 'binariesChildWorker']);
				$this->work = $this->pdo->query('SELECT name FROM groups WHERE active = 1');
				$maxProcesses = $this->pdo->getSetting('binarythreads');
				break;

			case 'releases':
				$this->register_child_run([0 => $this, 1 => 'releasesChildWorker']);
				$this->work = $this->pdo->query('SELECT name FROM groups WHERE (active = 1 OR backfill = 1)');
				$maxProcesses = $this->pdo->getSetting('releasesthreads');
				break;

			case 'postProcess_ama':
				$this->processSingle(false);
				break;

			case 'postProcess_add':
				if ($this->checkProcessAdditional() === true) {
					$this->processAdditional = true;
					$this->register_child_run([0 => $this, 1 => 'postProcessChildWorker']);
					$this->work = $this->pdo->query(
						sprintf('
							SELECT DISTINCT(g.id)
							FROM groups g
							INNER JOIN releases r ON r.group_id = g.id
							LEFT JOIN category c ON c.id = r.categoryid
							WHERE r.nzbstatus = %d
							AND r.passwordstatus BETWEEN -6 AND -1
							AND r.haspreview = -1
							AND c.disablepreview = 0',
							\NZB::NZB_ADDED
						)
					);
					$maxProcesses = $this->pdo->getSetting('maxaddprocessed');
				}
				break;

			case 'postProcess_mov':
				if ($this->checkProcessMovies() === true) {
					$this->processMovies = true;
					$this->register_child_run([0 => $this, 1 => 'postProcessChildWorker']);
					$this->work = $this->pdo->query(
						sprintf('
							SELECT DISTINCT(g.id)
							FROM groups g
							INNER JOIN releases r ON r.group_id = g.id
							WHERE r.nzbstatus = %d
							AND r.imdbid IS NULL
							AND r.categoryid BETWEEN 2000 AND 2999',
							\NZB::NZB_ADDED
						)
					);
					$maxProcesses = $this->pdo->getSetting('maximdbprocessed');
				}
				break;

			case 'postProcess_nfo':
				if ($this->checkProcessNfo() === true) {
					$this->processNFO = true;
					$this->register_child_run([0 => $this, 1 => 'postProcessChildWorker']);
					$this->work = $this->pdo->query(
						sprintf('
							SELECT DISTINCT(g.id)
							FROM groups g
							INNER JOIN releases r ON r.group_id = g.id
							WHERE r.nzbstatus = %d
							AND r.nfostatus BETWEEN -6 AND -1',
							\NZB::NZB_ADDED
						)
					);
					$maxProcesses = $this->pdo->getSetting('maxnfoprocessed');
				}
				break;

			case 'postProcess_sha':
				$postProcess = new \PostProcess(true);
				$this->processSharing($postProcess);
				break;

			case 'postProcess_tv':
				if ($this->checkProcessTV() === true) {
					$this->processTV = true;
					$this->register_child_run([0 => $this, 1 => 'postProcessChildWorker']);
					$this->work = $this->pdo->query(
						sprintf('
							SELECT DISTINCT(g.id)
							FROM groups g
							INNER JOIN releases r ON r.group_id = g.id
							WHERE r.nzbstatus = %d
							AND r.rageid = -1
							AND r.size > 1048576
							AND r.categoryid BETWEEN 5000 AND 5999',
							\NZB::NZB_ADDED
						)
					);
					$maxProcesses = $this->pdo->getSetting('maxrageprocessed');
				}
				break;
		}

		if (is_numeric($maxProcesses) && $maxProcesses > 0) {
			$this->max_children_set($maxProcesses);
		}
	}

	/**
	 * Process sharing.
	 *
	 * @param \PostProcess $postProcess
	 *
	 * @return bool
	 */
	private function processSharing(&$postProcess)
	{
		$sharing = $this->pdo->queryOneRow('SELECT enabled FROM sharing');
		if ($sharing !== false && $sharing['enabled'] == 1) {
			$nntp = new \NNTP(true);
			if (($this->pdo->getSetting('alternate_nntp') == 1 ? $nntp->doConnect(true, true) : $nntp->doConnect()) === true) {
				$postProcess->processSharing($nntp);
			}
			return true;
		}
		return false;
	}

	/**
	 * Process all that require a single thread.
	 *
	 * @param bool $sharing Process sharing?
	 */
	private function processSingle($sharing = true)
	{
		$postProcess = new \PostProcess(true);
		if ($sharing) {
			$this->processSharing($postProcess);
		}
		//$postProcess->processAnime();
		$postProcess->processBooks();
		$postProcess->processConsoles();
		$postProcess->processGames();
		$postProcess->processMusic();
		$postProcess->processXXX();
	}

	/**
	 * Check if we should process NFO's.
	 * @return bool
	 */
	private function checkProcessNfo()
	{
		if ($this->pdo->getSetting('lookupnfo') == 1) {
			return (
				$this->pdo->queryOneRow(
					sprintf(
						'SELECT id FROM releases WHERE nzbstatus = %d AND nfostatus BETWEEN -1 AND %d LIMIT 1',
						\NZB::NZB_ADDED, \Nfo::NFO_UNPROC
					)
				) === false ? false : true
			);
		}
		return false;
	}

	/**
	 * Check if we should process Additional's.
	 * @return bool
	 */
	private function checkProcessAdditional()
	{
		return (
			$this->pdo->queryOneRow(
				sprintf('
					SELECT r.id
					FROM releases r
					LEFT JOIN category c ON c.id = r.categoryid
					WHERE r.nzbstatus = %d
					AND r.passwordstatus BETWEEN -6 AND -1
					AND r.haspreview = -1
					AND c.disablepreview = 0
					LIMIT 1',
					\NZB::NZB_ADDED
				)
			) === false ? false : true
		);
	}

	/**
	 * Check if we should process Movies.
	 * @return bool
	 */
	private function checkProcessMovies()
	{
		if ($this->pdo->getSetting('lookupimdb') == 1) {
			return (
				$this->pdo->queryOneRow(
					sprintf('
						SELECT id
						FROM releases
						WHERE nzbstatus = %d
						AND imdbid IS NULL
						AND categoryid BETWEEN 2000 AND 2999
						LIMIT 1',
						\NZB::NZB_ADDED
					)
				) === false ? false : true
			);
		}
		return false;
	}

	/**
	 * Check if we should process TV's.
	 * @return bool
	 */
	private function checkProcessTV()
	{
		if ($this->pdo->getSetting('lookuptvrage') == 1) {
			return (
				$this->pdo->queryOneRow(
					sprintf('
						SELECT id
						FROM releases
						WHERE nzbstatus = %d
						AND size > 1048576
						AND rageid = -1
						AND categoryid BETWEEN 5000 AND 5999
						LIMIT 1',
						\NZB::NZB_ADDED
					)
				) === false ? false : true
			);
		}
		return false;
	}

	/**
	 * Process work if we have any.
	 */
	private function processWork()
	{
		if (count($this->work) > 0) {
			$this->addwork($this->work);
			$this->process_work(true);
		}
	}

	/**
	 * @var \nzedb\db\Settings
	 */
	private $pdo;

	/**
	 *
	 */
	public function __destruct()
	{
		parent::__destruct();
	}

	/**
	 * All the following methods should not be accessed, they are used by the parent class.
	 */

	/**
	 * Echo a message to CLI.
	 *
	 * @param string $message
	 */
	public function logger($message)
	{
		if (nZEDb_ECHOCLI) {
			echo $message . PHP_EOL;
		}
	}

	/**
	 * This method is executed whenever a child is finished doing work.
	 *
	 * @param string $pid        The PID numbers.
	 * @param string $identifier Optional identifier to give a PID a name.
	 */
	public function childExit($pid, $identifier = '')
	{
		echo 'Process ID #' . $pid . ' has completed.' . PHP_EOL;
	}

	/**
	 * The following methods are where the work is done with the data sent from the parent process.
	 *
	 * @param array  $groups     Array of group data.
	 * @param string $identifier Optional identifier to give a PID a name.
	 */
	public function backFillChildWorker($groups, $identifier = '')
	{
		foreach ($groups as $group) {
			passthru(
				PHP_BINARY . ' ' . nZEDb_MISC . 'update' . DS . 'backfill.php ' .
				$group['name'] . (isset($group['max']) ? (' ' . $group['max']) : ''));
		}
	}

	public function binariesChildWorker($groups, $identifier = '')
	{
		foreach ($groups as $group) {
			passthru(PHP_BINARY . ' ' . nZEDb_MISC . 'update' . DS . 'update_binaries.php ' . $group['name']);
		}
	}

	public function releasesChildWorker($groups, $identifier = '')
	{
		foreach ($groups as $group) {
			passthru(PHP_BINARY . ' ' . nZEDb_MISC . 'update' . DS . 'update_releases.php 1 false ' . $group['name']);
		}
	}

	private $processAdditional = false;
	private $processNFO = false;
	private $processMovies = false;
	private $processTV = false;
	public function postProcessChildWorker($groups, $identifier = '')
	{
		foreach ($groups as $group) {
			if ($this->processAdditional) {
				passthru(PHP_BINARY . ' ' . nZEDb_MISC . 'update' . DS . 'postprocess.php additional true ' . $group['id']);
			}
			if ($this->processNFO) {
				passthru(PHP_BINARY . ' ' . nZEDb_MISC . 'update' . DS . 'postprocess.php nfo true ' . $group['id']);
			}
			if ($this->processMovies) {
				passthru(PHP_BINARY . ' ' . nZEDb_MISC . 'update' . DS . 'postprocess.php movies true ' . $group['id']);
			}
			if ($this->processTV) {
				passthru(PHP_BINARY . ' ' . nZEDb_MISC . 'update' . DS . 'postprocess.php tv true ' . $group['id']);
			}
		}
	}
}

class ForkingException extends \Exception {}