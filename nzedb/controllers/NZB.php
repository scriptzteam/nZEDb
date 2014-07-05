<?php

/**
 * Class for reading and writing NZB files on the hard disk,
 * building folder paths to store the NZB files.
 */
class NZB
{
	/**
	 * Instance of class site.
	 * @var object
	 * @access private
	 */
	private $s;

	/**
	 * Determines if the site setting table per group is enabled.
	 * @var int
	 * @access private
	 */
	private $tablePerGroup;

	/**
	 * Group ID when writing NZBs.
	 * @var int
	 * @access protected
	 */
	protected $groupID;

	/**
	 * Instance of class db.
	 * @var nzedb\db\DB
	 * @access protected
	 */
	protected $pdo;

	/**
	 * Date when writing NZBs.
	 * @var string
	 * @access protected
	 */
	protected $writeDate;

	/**
	 * Current nZEDb version.
	 * @var string
	 * @access protected
	 */
	protected $_nZEDbVersion;

	/**
	 * Base query for selecting collection data for writing NZB files.
	 * @var string
	 * @access protected
	 */
	protected $_collectionsQuery;

	/**
	 * Base query for selecting binary data for writing NZB files.
	 * @var string
	 * @access protected
	 */
	protected $_binariesQuery;

	/**
	 * Base query for selecting parts data for writing NZB files.
	 * @var string
	 * @access protected
	 */
	protected $_partsQuery;

	/**
	 * String used for head in NZB XML file.
	 * @var string
	 * @access protected
	 */
	protected $_nzbHeadString;

	const NZB_NONE  = 0; // Release has no NZB file yet.
	const NZB_ADDED = 1; // Release had an NZB file created.

	/**
	 * Default constructor.
	 *
	 * @access public
	 */
	public function __construct()
	{
		$this->pdo = new \nzedb\db\Settings();
		$tpg = $this->pdo->getSetting('tablepergroup');
		$this->tablePerGroup = isset($tpg) ? (int)$tpg : 0;
	}

	/**
	 * Initiate class vars when writing NZB's.
	 *
	 * @param nzedb\db\DB $pdo
	 * @param string $date
	 * @param int $groupID
	 *
	 * @access public
	 */
	public function initiateForWrite($pdo, $date, $groupID)
	{
		$this->pdo = $pdo;
		$this->writeDate = $date;
		$this->groupID = $groupID;
		// Set table names
		if ($this->tablePerGroup === 1) {
			if ($this->groupID == '') {
				exit("$this->groupID is missing\n");
			}
			$cName = 'collections_' .$this->groupID;
			$bName = 'binaries_' . $this->groupID;
			$pName = 'parts_' . $this->groupID;
		} else {
			$cName = 'collections';
			$bName = 'binaries';
			$pName = 'parts';
		}

		$this->_collectionsQuery = sprintf(
			'SELECT %s.*, UNIX_TIMESTAMP(%s.date) AS udate, groups.name AS groupname
			FROM %s
			INNER JOIN groups ON %s.group_id = groups.id
			WHERE %s.releaseid',
			$cName,
			$cName,
			$cName,
			$cName,
			$cName
		);
		$this->_binariesQuery = (
			'SELECT id, name, totalparts FROM '. $bName .' WHERE collectionid = %d ORDER BY name'
		);
		$this->_partsQuery = (
			'SELECT DISTINCT(messageid), size, partnumber FROM ' . $pName . ' WHERE binaryid = %d ORDER BY partnumber'
		);

		$this->_nzbHeadString = (
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE nzb PUBLIC \"-//newzBin//DTD NZB 1.1//EN\" \"http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd\">\n<!-- NZB Generated by: nZEDb " .
			$this->s->version() . ' ' . $date .
			" -->\n<nzb xmlns=\"http://www.newzbin.com/DTD/2003/nzb\">\n<head>\n <meta type=\"category\">%s</meta>\n <meta type=\"name\">%s</meta>\n</head>\n\n"
		);
	}

	/**
	 * Clean up class vars when done writing NZB's.
	 *
	 * @access public
	 */
	public function cleanForWrite()
	{
		$this->writeDate = null;
		$this->groupID = null;
	}

	/**
	 * Write an NZB to the hard drive for a single release.
	 *
	 * @param int    $relID   The ID of the release in the DB.
	 * @param string $relGuid The guid of the release.
	 * @param string $name    The name of the release.
	 * @param string $cTitle  The name of the category this release is in.
	 *
	 * @return bool Have we successfully written the NZB to the hard drive?
	 *
	 * @access public
	 */
	public function writeNZBforReleaseId($relID, $relGuid, $name, $cTitle)
	{
		$path = ($this->buildNZBPath($relGuid, $this->pdo->getSetting('nzbsplitlevel'), true) . $relGuid . '.nzb.gz');
		$fp = gzopen($path, 'w7');
		if ($fp) {
			$nzb_guid = '';
			gzwrite(
				$fp,
				sprintf(
					$this->_nzbHeadString,
					htmlspecialchars($cTitle, ENT_QUOTES, 'utf-8'),
					htmlspecialchars($name, ENT_QUOTES, 'utf-8')
				)
			);

			$collections =
				$this->pdo->queryDirect(
					sprintf(
						'%s = %d',
						$this->_collectionsQuery,
						$relID
					)
				);

			foreach ($collections as $collection) {
				$poster = htmlspecialchars($collection['fromname'], ENT_QUOTES, 'utf-8');
				$binaries = $this->pdo->queryDirect(
					sprintf(
						$this->_binariesQuery,
						$collection['id']
					)
				);

				foreach ($binaries as $binary) {
					gzwrite($fp,
						'<file poster="' . $poster .
						'" date="' . $collection['udate'] .
						'" subject="' .
						htmlspecialchars($binary['name'], ENT_QUOTES, 'utf-8') .
						' (1/' . $binary['totalparts'] .
						")\">\n <groups>\n  <group>" . $collection['groupname'] .
						"</group>\n </groups>\n <segments>\n"
					);

					$parts = $this->pdo->queryDirect(
						sprintf(
							$this->_partsQuery,
							$binary['id']
						)
					);

					// Buffer segment writes, increases performance.
					$string = '';

					foreach ($parts as $part) {
						if ($nzb_guid === '') {
							$nzb_guid = $part['messageid'];
						}
						$string .= (
							'  <segment bytes="' . $part['size']
							. '" number="' . $part['partnumber'] . '">'
							. htmlspecialchars($part['messageid'], ENT_QUOTES, 'utf-8')
							. "</segment>\n"
						);
					}

					gzwrite($fp, $string . " </segments>\n</file>\n");
				}
			}
			gzwrite($fp, '</nzb>');
			gzclose($fp);

			if (is_file($path)) {
				$this->pdo->queryExec(
					sprintf('
						UPDATE releases SET nzbstatus = %d %s WHERE id = %d',
						NZB::NZB_ADDED,
						($nzb_guid === '' ? '' : ', nzb_guid = ' . $this->pdo->escapestring(md5($nzb_guid))),
						$relID
					)
				);

				// Chmod to fix issues some users have with file permissions.
				chmod($path, 0777);
				return true;
			} else {
				echo "ERROR: $path does not exist.\n";
			}
		}
		return false;
	}

	/**
	 * Build a folder path on the hard drive where the NZB file will be stored.
	 *
	 * @param string $releaseGuid         The guid of the release.
	 * @param int    $levelsToSplit       How many sub-paths the folder will be in.
	 * @param bool   $createIfNotExist Create the folder if it doesn't exist.
	 *
	 * @return string $nzbpath The path to store the NZB file.
	 *
	 * @access public
	 */
	private function buildNZBPath($releaseGuid, $levelsToSplit, $createIfNotExist)
	{
		$siteNzbPath = $this->pdo->getSetting('nzbpath');
		if (substr($siteNzbPath, -1) !== DS) {
			$siteNzbPath .= DS;
		}

		$nzbPath = '';

		for ($i = 0; $i < $levelsToSplit; $i++) {
			$nzbPath .= substr($releaseGuid, $i, 1) . DS;
		}

		$nzbPath = $siteNzbPath . $nzbPath;

		if ($createIfNotExist && !is_dir($nzbPath)) {
			mkdir($nzbPath, 0777, true);
		}

		return $nzbPath;
	}

	/**
	 * Retrieve path + filename of the NZB to be stored.
	 *
	 * @param string $releaseGuid         The guid of the release.
	 * @param int    $levelsToSplit       How many sub-paths the folder will be in. (optional)
	 * @param bool   $createIfNotExist Create the folder if it doesn't exist. (optional)
	 *
	 * @return string Path+filename.
	 *
	 * @access public
	 */
	public function getNZBPath($releaseGuid, $levelsToSplit=0, $createIfNotExist = false)
	{
		if ($levelsToSplit === 0) {
			$levelsToSplit = $this->pdo->getSetting('nzbsplitlevel');
		}

		return ($this->buildNZBPath($releaseGuid, $levelsToSplit, $createIfNotExist) . $releaseGuid . '.nzb.gz');
	}

	/**
	 * Determine is an NZB exists, returning the path+filename, if not return false.
	 *
	 * @param  string $releaseGuid              The guid of the release.
	 *
	 * @return bool|string On success: (string) Path+file name of the nzb.
	 *                     On failure: (bool)   False.
	 *
	 * @access public
	 */
	public function NZBPath($releaseGuid)
	{
		$nzbFile = $this->getNZBPath($releaseGuid);
		return !is_file($nzbFile) ? false : $nzbFile;
	}

	/**
	 * Retrieve various information on a NZB file (the subject, # of pars,
	 * file extensions, file sizes, file completion, group names, # of parts).
	 *
	 * @param string $nzb The NZB contents in a string.
	 *
	 * @return array $result Empty if not an NZB or the contents of the NZB.
	 *
	 * @access public
	 */
	public function nzbFileList($nzb)
	{
		$num_pars = $i = 0;
		$result = array();

		$nzb = str_replace("\x0F", '', $nzb);
		$xml = @simplexml_load_string($nzb);
		if (!$xml || strtolower($xml->getName()) !== 'nzb') {
			return $result;
		}

		foreach ($xml->file as $file) {
			// Subject.
			$title = (string)$file->attributes()->subject;

			// Amount of pars.
			if (stripos($title, '.par2')) {
				$num_pars++;
			}

			$result[$i]['title'] = $title;

			// Extensions.
			if (preg_match(
					'/\.(\d{2,3}|7z|ace|ai7|srr|srt|sub|aiff|asc|avi|audio|bin|bz2|'
					. 'c|cfc|cfm|chm|class|conf|cpp|cs|css|csv|cue|deb|divx|doc|dot|'
					. 'eml|enc|exe|file|gif|gz|hlp|htm|html|image|iso|jar|java|jpeg|'
					. 'jpg|js|lua|m|m3u|mkv|mm|mov|mp3|mp4|mpg|nfo|nzb|odc|odf|odg|odi|odp|'
					. 'ods|odt|ogg|par2|parity|pdf|pgp|php|pl|png|ppt|ps|py|r\d{2,3}|'
					. 'ram|rar|rb|rm|rpm|rtf|sfv|sig|sql|srs|swf|sxc|sxd|sxi|sxw|tar|'
					. 'tex|tgz|txt|vcf|video|vsd|wav|wma|wmv|xls|xml|xpi|xvid|zip7|zip)'
					. '[" ](?!(\)|\-))/i',
					$title, $ext
				)
			) {

				if (preg_match('/\.r\d{2,3}/i', $ext[0])) {
					$ext[1] = 'rar';
				}
				$result[$i]['ext'] = strtolower($ext[1]);
			} else {
				$result[$i]['ext'] = '';
			}

			$fileSize = $numSegments = 0;

			// Parts.
			if (!isset($result[$i]['segments'])) {
				$result[$i]['segments'] = array();
			}

			// File size.
			foreach ($file->segments->segment as $segment) {
				array_push($result[$i]['segments'], (string) $segment);
				$fileSize += $segment->attributes()->bytes;
				$numSegments++;
			}
			$result[$i]['size'] = $fileSize;

			// File completion.
			if (preg_match('/(\d+)\)$/', $title, $parts)) {
				$result[$i]['partstotal'] = $parts[1];
			}
			$result[$i]['partsactual'] = $numSegments;

			// Groups.
			if (!isset($result[$i]['groups'])) {
				$result[$i]['groups'] = array();
			}
			foreach ($file->groups->group as $g) {
				array_push($result[$i]['groups'], (string) $g);
			}

			unset($result[$i]['segments']['@attributes']);
			$i++;
		}
		return $result;
	}

}
