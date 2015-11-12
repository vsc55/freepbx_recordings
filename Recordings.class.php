<?php
// vim: set ai ts=4 sw=4 ft=php:

class Recordings implements BMO {
	private $initialized = false;
	private $full_list = null;
	private $filter_list = array();
	private $temp;
	private $path;
	private $fcbase = "*29";
	/** Extensions to show in the convert to section
	 * Limited on purpose because there are far too many,
	 * Most of which are not supported by asterisk
	 */
	private $convert = array(
		"wav",
		"sln",
		"g722",
		"ulaw",
		"alaw",
		"g729",
		"gsm",
		"wav49",
		"g719"
	);

	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new Exception("Not given a FreePBX Object");
		}

		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$tmp = sys_get_temp_dir();
		$this->temp = !empty($tmp) && file_exists($tmp) ? $tmp : "/tmp";
		$this->path = $this->FreePBX->Config->get("ASTVARLIBDIR")."/sounds";
	}

	public function doConfigPageInit($page) {

	}

	public function install() {

	}
	public function uninstall() {

	}
	public function backup(){

	}
	public function restore($backup){

	}
	public function genConfig() {

	}

	public function getActionBar($request){
		$buttons = array();
		if(isset($request['action']) && ($request['action'] == "add" || $request['action'] == "edit")) {
			$buttons = array(
				'submit' => array(
					'name' => 'submit',
					'id' => 'submit',
					'value' => _("Submit"),
				),
				'delete' => array(
					'name' => 'delete',
					'id' => 'delete',
					'value' => _("Delete"),
				),
				'reset' => array(
					'name' => 'reset',
					'id' => 'reset',
					'value' => _("Reset"),
				)
			);
			if($request['action'] == "add") {
				unset($buttons['delete']);
			}
		}
		return $buttons;
	}

	public function showPage() {
		$media = $this->FreePBX->Media();
		$action = !empty($_REQUEST['action']) ? $_REQUEST['action'] : "";
		switch($action) {
			case "edit":
				$data = $this->getRecordingById($_REQUEST['id']);
				$fcc = new \featurecode("recordings", 'edit-recording-'.$_REQUEST['id']);
				$rec_code = $fcc->getCode();
				$data['rec_code'] = ($rec_code != '') ? $rec_code : $this->fcbase.$_REQUEST['id'];
			case "add":
				$data = isset($data) ? $data : array();
				$supported = $media->getSupportedFormats();
				ksort($supported['in']);
				ksort($supported['out']);
				$langs = $this->FreePBX->Soundlang->getLanguages();
				$default = $this->FreePBX->Soundlang->getLanguage();
				$sysrecs = $this->getSystemRecordings();
				$supportedHTML5 = $media->getSupportedHTML5Formats();
				$convertto = array_intersect($supported['out'], $this->convert);
				$html = load_view(__DIR__."/views/form.php",array("convertto" => $convertto, "supportedHTML5" => implode(",",$supportedHTML5), "data" => $data, "default" => $default, "supported" => $supported, "langs" => $langs, "sysrecs" => $sysrecs));
			break;
			case "delete":
				$this->delRecording($_REQUEST['id']);
			default:
				$html = load_view(__DIR__."/views/grid.php",array());
			break;
		}
		return $html;
	}

	public function ajaxRequest($req, &$setting) {
		$setting['authenticate'] = false;
		$setting['allowremote'] = false;
		switch($req) {
			case "dialrecording":
			case "checkrecording":
			case "savebrowserrecording":
			case "saverecording":
			case "deleterecording":
			case "save":
			case "record":
			case "upload":
			case "grid":
			case "gethtml5":
			case "playback":
			case "download":
				return true;
			break;
		}
		return false;
	}

	public function ajaxCustomHandler() {
		switch($_REQUEST['command']) {
			case "playback":
			case "download":
				$media = $this->FreePBX->Media();
				$media->getHTML5File($_REQUEST['file']);
			break;
		}
	}

	public function ajaxHandler() {
		switch($_REQUEST['command']) {
			case "gethtml5":
				$media = $this->FreePBX->Media();
				$lang = basename($_POST['language']);
				$info = pathinfo($_POST['filenames'][$lang]);
				$path = ($_POST['temporary'][$lang]) ? $this->temp : $this->path;
				if(empty($info['extension'])) {
					$file = preg_replace("/^".$lang."\//i", "", $_POST['filenames'][$lang]);
					$status = $this->fileStatus($file, !($_POST['temporary'][$lang]));
					if(!empty($status[$lang])) {
						$filename = $path . "/" . $lang . "/" . reset($status[$lang]);
					}
				} else {
					$filename = $path . "/" . $_POST['filenames'][$lang];
				}
				$media->load($filename);
				$files = $media->generateHTML5();
				$final = array();
				foreach($files as $format => $name) {
					$final[$format] = "ajax.php?module=recordings&command=playback&file=".$name;
				}
				return array("status" => true, "files" => $final);
			break;
			case "save":
				set_time_limit(0);
				//Save the FINAL recording. Do all post processing work here as well
				$data = $_POST;
				$data['soundlist'] = json_decode($data['soundlist'],true);
				$playback = array();
				$media = $this->FreePBX->Media;
				$errors = array();
				//convert files
				foreach($data['soundlist'] as $list) {
					$list['name'] = preg_replace("/\s+|'+|`+|\"+|<+|>+|\?+|\*|\.+|&+/","-",$list['name']);
					$playback[] = $list['name'];
					foreach($list['filenames'] as $lang => $file) {
						if(!file_exists($this->path."/".$lang."/custom")) {
							mkdir($this->path."/".$lang."/custom",0777,true);
						}
						if(!empty($data['codecs'])) {
							if($list['temporary'][$lang]) {
								$media->load($this->temp."/".$file);
							} else {
								$status = $this->fileStatus($list['name']);
								if(!empty($status[$lang])) {
									$file = $lang."/".reset($status[$lang]);
								} else {
									//continue;
								}
								$media->load($this->path."/".$file);
							}
							foreach($data['codecs'] as $codec) {
								if(file_exists($this->path."/".$lang."/".$list['name'].".".$codec)) {
									//TODO: need a way to know it's ok to overwrite a sysrecording
									continue;
								}
								try {
									$media->convert($this->path."/".$lang."/".$list['name'].".".$codec);
								} catch(\Exception $e) {
									$errors[] = $e->getMessage()." [".$this->path."/".$file.".".$codec."]";
								}
							}
							if($list['temporary'][$lang] && file_exists($this->temp."/".$file)) {
								unlink($this->temp."/".$file);
							}
						} else {
							$ext = pathinfo($file,PATHINFO_EXTENSION);
							if($list['temporary'][$lang] && file_exists($this->temp."/".$file) && !file_exists($this->path."/".$lang."/".$list['name'].".".$ext)) {
								rename($this->temp."/".$file, $this->path."/".$lang."/".$list['name'].".".$ext);
							} elseif($list['temporary'][$lang] && file_exists($this->path."/".$lang."/".$list['name'].".".$ext)) {
								//TODO: need a way to know it's ok to overwrite a sysrecording
								continue;
							}

						}
					}
				}
				if($data['id'] == "0" || !empty($data['id'])) {
					$this->updateRecording($data['id'],$data['name'],$data['description'],implode("&",$playback),$data['fcode'],$data['fcode_pass']);
				} else {
					$this->addRecording($data['name'],$data['description'],implode("&",$playback),$data['fcode'],$data['fcode_pass']);
				}
				if(empty($errors)) {
					return array("status" => true);
				} else {
					return array("status" => false, "message" => "error", "errors" => $errors);
				}
			break;
			case "savebrowserrecording":
				if ($_FILES["file"]["error"] == UPLOAD_ERR_OK) {
					$time = time().rand(1,1000);
					$filename = basename($_REQUEST['filename'])."-".$time.".wav";
					move_uploaded_file($_FILES["file"]["tmp_name"], $this->temp."/".$filename);
					return array("status" => true, "filename" => $_REQUEST['filename'], "localfilename" => $filename);
				}	else {
					return array("status" => false, "message" => _("Unknown Error"));
				}
			break;
			case "deleterecording":
				$files = json_decode($_POST['filenames'],true);
				foreach($files as $lang => $file) {
					$file = basename($file);
					if(file_exists($this->temp."/".$file)) {
						unlink($this->temp."/".$file);
					}
				}
				return array("status" => true);
			break;
			case "dialrecording":
				$astman = $this->FreePBX->astman;
				$status = $astman->originate(array(
					"Channel" => "Local/".$_POST['extension']."@from-internal",
					"Exten" => "dorecord",
					"Context" => "systemrecording-gui",
					"Priority" => 1,
					"Async" => "no",
					"CallerID" => _("System Recordings"),
					"Variable" => "RECFILE=/tmp/".basename($_POST['filename'])
				));
				if($status['Response'] == "Success") {
					return array("status" => true);
				} else {
					return array("status" => false, "message" => $status['Message']);
				}
			break;
			case "checkrecording":
				$filename = !empty($_POST['filename']) ? basename($_POST['filename']) : '';
				if(file_exists($this->temp."/".$filename.".finished")) {
					unlink($this->temp."/".$filename.".finished");
					return array("finished" => true, "filename" => $filename, "localfilename" => $filename.".wav", "recording" => false);
				} elseif(file_exists($this->temp."/".$filename.".wav")) {
					return array("finished" => false, "recording" => true);
				} else {
					return array("finished" => false, "recording" => false);
				}
			break;
			case "saverecording":
				$name = !empty($_POST['name']) ? basename($_POST['name']) : '';
				$filename = !empty($_POST['filename']) ? basename($_POST['filename']) : '';
				$time = time().rand(1,1000);
				$fname = $name."-".$time.".wav";
				if(file_exists($this->temp."/".$filename.".wav")) {
					rename($this->temp."/".$filename.".wav", $this->temp."/".$fname);
					return array("status" => true, "filename" => $name, "localfilename" => $fname);
				} else {
					return array("status" => false, "message" => _("File does not exist"));
				}
			break;
			case "grid";
				$all = $this->getAll();
				$languageNames = $this->FreePBX->Soundlang->getLanguages();
				foreach($all as &$recs) {
					foreach($recs['languages'] as &$lang) {
						$lang = isset($languageNames[$lang]) ? $languageNames[$lang] : $lang;
					}
					$recs['languages'] = implode(", ", $recs['languages']);
				}
				return $all;
			break;
			case "upload":
				foreach ($_FILES["files"]["error"] as $key => $error) {
					switch($error) {
						case UPLOAD_ERR_OK:
							$extension = pathinfo($_FILES["files"]["name"][$key], PATHINFO_EXTENSION);
							$extension = strtolower($extension);
							$supported = $this->FreePBX->Media->getSupportedFormats();
							if(in_array($extension,$supported['in'])) {
								$tmp_name = $_FILES["files"]["tmp_name"][$key];
								$dname = strtolower($_FILES["files"]["name"][$key]);
								$dname = pathinfo($dname,PATHINFO_FILENAME);
								$dname = preg_replace("/\s+|'+|`+|\"+|<+|>+|\?+|\*|\.+|&+/","-",$dname);
								$id = time().rand(1,1000);
								$name = $dname . '-' . $id . '.' . $extension;
								move_uploaded_file($tmp_name, $this->temp."/".$name);
								return array("status" => true, "filename" => pathinfo($dname,PATHINFO_FILENAME), "localfilename" => $name, "id" => $id);
							} else {
								return array("status" => false, "message" => _("Unsupported file format"));
								break;
							}
						break;
						case UPLOAD_ERR_INI_SIZE:
							return array("status" => false, "message" => _("The uploaded file exceeds the upload_max_filesize directive in php.ini"));
						break;
						case UPLOAD_ERR_FORM_SIZE:
							return array("status" => false, "message" => _("The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form"));
						break;
						case UPLOAD_ERR_PARTIAL:
							return array("status" => false, "message" => _("The uploaded file was only partially uploaded"));
						break;
						case UPLOAD_ERR_NO_FILE:
							return array("status" => false, "message" => _("No file was uploaded"));
						break;
						case UPLOAD_ERR_NO_TMP_DIR:
							return array("status" => false, "message" => _("Missing a temporary folder"));
						break;
						case UPLOAD_ERR_CANT_WRITE:
							return array("status" => false, "message" => _("Failed to write file to disk"));
						break;
						case UPLOAD_ERR_EXTENSION:
							return array("status" => false, "message" => _("A PHP extension stopped the file upload"));
						break;
					}
				}
				return array("status" => false, "message" => _("Can Not Find Uploaded Files"));
			break;
		}
	}

	/**
	 * Add New Recording
	 * @param string  $name        The recording short name
	 * @param string  $description The recording long name
	 * @param string  $files       & separated list of files to playback
	 * @param integer $fcode       Feature Code number_format
	 * @param string  $fcode_pass  Feature code password
	 */
	public function addRecording($name,$description,$files,$fcode=0,$fcode_pass='') {
		$sql = "INSERT INTO recordings (displayname, description, filename, fcode, fcode_pass) VALUES(?,?,?,?,?)";
		$sth = $this->db->prepare($sql);
		$sth->execute(array($name, $description, $files, $fcode, $fcode_pass));
		needreload();
	}

	/**
	 * Update Recording by ID
	 * @param integer $id          The recording ID
	 * @param string  $name        The recording short name
	 * @param string  $description The recording long name
	 * @param string  $files       & separated list of files to playback
	 * @param integer $fcode       Feature Code number_format
	 * @param string  $fcode_pass  Feature code password
	 */
	public function updateRecording($id,$name,$description,$files,$fcode=0,$fcode_pass='') {
		$sql = "UPDATE recordings SET displayname = ?, description = ?, filename = ?, fcode = ?, fcode_pass = ? WHERE id = ?";
		$sth = $this->db->prepare($sql);
		$sth->execute(array($name, $description, $files, $fcode, $fcode_pass, $id));
		if ($fcode != 1) {
			// delete the feature code if it existed
			//
			$fcc = new \featurecode('recordings', 'edit-recording-'.$id);
			$fcc->delete();
			unset($fcc);
		} else {
			// Add the feature code if it is needed
			//
			$fcc = new \featurecode('recordings', 'edit-recording-'.$id);
			$fcc->setDescription("Edit Recording: $name");
			$fcc->setDefault('*29'.$id);
			$fcc->setProvideDest();
			$fcc->update();
			unset($fcc);
		}
		needreload();
	}

	/**
	 * Delete a recording by ID
	 * @param  integer $id The recording ID
	 */
	public function delRecording($id) {
		$sql = "DELETE FROM recordings WHERE id = ?";
		$sth = $this->db->prepare($sql);
		$sth->execute(array($id));
		needreload();
	}

	/**
	 * Alias of getRecordingById
	 * @param  integer $id The recording ID
	 * @return array     Array of information about the recording
	 */
	public function getRecordingsById($id) {
		return $this->getRecordingById($id);
	}

	/**
	 * Get a recording by it's ID
	 * @param  integer $id The recording ID
	 * @return array     Array of information about the recording
	 */
	public function getRecordingById($id) {
		$sql = "SELECT * FROM recordings where id= ?";
		$sth = $this->db->prepare($sql);
		if ($sth->execute(array($id))) {
			$data = $sth->fetch(\PDO::FETCH_ASSOC);
		}
		if (empty($data)) {
			return array();
		}
		$data['soundlist'] = array();
		$langs = array();
		$files = explode("&",$data['filename']);
		foreach($files as $file) {
			$status = $this->fileStatus($file);
			$data['soundlist'][$file] = array(
				"name" => $file,
				"temporary" => array(),
				"languages" => array(),
				"filenames" => array()
			);
			foreach($status as $lang => $formats) {
				foreach($formats as $format => $filename) {
					$data['soundlist'][$file]['filenames'][$lang] = $lang."/".$file;
				}
				$data['soundlist'][$file]['languages'][] = $lang;
				$data['soundlist'][$file]['temporary'][$lang] = 0;
			}
		}
		return $data;
	}

	/**
	 * Get filename(s) by recording ID
	 * @param  integer $id The recording ID
	 * @return array     Array of filenames
	 */
	public function getFilenameById($id) {
		$res = $this->getRecordingsById($id);
		if (empty($res)) {
			return '';
		}
		return $res['filename'];
	}

	/**
	 * Get all system recordings
	 * @return array Array of system recordings
	 */
	public function getSystemRecordings() {
		$files = $this->getdir($this->path);
		$final = array();
		foreach($files as &$file) {
			$file = str_replace($this->path."/","",$file);
			if(preg_match("/^(\w{2}_\w{2}|\w{2})\/(.*)\.([a-z0-9]{2,})/i",$file,$matches)) {
				$lang = $matches[1];
				$name = $matches[2];
				if(substr($name, 0, 1) == "." || preg_match("/^(?:CHANGES|CREDITS|LICENSE)-asterisk-(?:core|extra)-(?:\w\w\_\w\w|\w\w)-(?:\d|\.)*$/i", $name)) {
					continue;
				}
				$format = $matches[3];
				if(!isset($final[$name])) {
					$final[$name] = array(
						"name" => $name,
						"languages" => array(
							$lang => $lang
						),
						"formats" => array(
							$format => $format
						),
						"paths" => array(
							$lang => $lang."/".$name
						)
					);
				} else {
					$final[$name]['languages'][$lang] = $lang;
					$final[$name]['formats'][$format] = $format;
					$final[$name]['paths'][$lang] = $lang."/".$name;
				}
			}
		}
		ksort($final);
		return $final;
	}

	/**
	 * Get all recordings in said Directory
	 * @param  string $snddir The directory to scan
	 * @return array         Array of files
	 */
	private function getdir($snddir) {
		$dir = opendir($snddir);
		$files = Array();
		while ($fn = readdir($dir)) {
			if ($fn == '.' || $fn == '..') { continue; }
			if (is_dir($snddir.'/'.$fn)) {
				$files = array_merge($this->getdir($snddir.'/'.$fn), $files);
				continue;
			}
			$files[] = $snddir.'/'.$fn;
		}
		return $files;
	}

	/**
	 * Get all recordings
	 * @return array Array of recordings
	 */
	public function getAll() {
		$sql = "SELECT * FROM recordings ORDER BY displayname";
		$sth = $this->db->prepare($sql);
		$sth->execute();
		$full_list = $sth->fetchAll(\PDO::FETCH_ASSOC);

		foreach($full_list as &$item) {
			$files = explode("&",$item['filename']);
			$item['files'] = array();
			$langs = array();
			foreach($files as $file) {
				$item['files'][$file] = $this->fileStatus($file);
				foreach(array_keys($item['files'][$file]) as $l) {
					if(!in_array($l,$langs)) {
						$langs[] = $l;
					}
				}
			}
			$item['languages'] = $langs;
			$item['missing']['languages'] = array();
			$item['missing']['formats'] = array();
			foreach($files as $file) {
				$diff = array_diff($langs,array_keys($item['files'][$file]));
				if(!empty($diff)) {
					$item['missing']['languages'][$file] = $diff;
				}
			}

		}
		return $full_list;
	}

	/**
	 * Status of filename
	 * EG are there multiple formats or languages
	 * @param  string $file   The filename
	 * @param  boolean $system Is this a system file or a temp file
	 * @return array         Array of file information
	 */
	public function fileStatus($file, $system = true) {
		$data = array();
		$path = ($system) ? $this->path : $this->temp;
		foreach(glob($path."/*",GLOB_ONLYDIR) as $langdir) {
			$lang = basename($langdir);
			foreach(glob($langdir."/".$file."*") as $f) {
				$parts = pathinfo($f);
				if(empty($parts['extension'])) {
					continue; //wtf is this file?
				}
				$data[$lang][$parts['extension']] = str_replace($langdir."/","",$f);
			}
		}
		return $data;
	}

	/**
	 * Get all of the recordings (the old way)
	 * @param  boolean $compound Whether to show compounded recordings or not
	 * @return array           Array of recordings
	 */
	public function getAllRecordings($compound = true) {
		if ($this->initialized) {
			return ($compound ? $this->full_list : $this->filter_list);
		}
		$this->initialized = true;

		$sql = "SELECT * FROM recordings where displayname <> '__invalid' ORDER BY displayname";
		$sth = $this->db->prepare($sql);
		$sth->execute();
		$this->full_list = $sth->fetchAll(\PDO::FETCH_ASSOC);
		foreach($this->full_list as &$item) {
			//TODO: Find instances of this and remove it!
			// Make array backward compatible, put first 4 columns as numeric
			$item[0] = $item['id'];
			$item[1] = $item['displayname'];
			$item[2] = $item['filename'];
			$item[3] = $item['description'];
			if (strstr($item['filename'],'&') === false) {
				$this->filter_list[] = $item;
			}
		}
		return ($compound ? $this->full_list : $this->filter_list);
	}
}
