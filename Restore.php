<?php
namespace FreePBX\modules\Recordings;
use FreePBX\modules\Backup as Base;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Finder\Finder;
class Restore Extends Base\RestoreBase{
	public function runRestore(){
		$configs = $this->getConfigs();
		$files = $this->getFiles();
		foreach($files as $file){
			if($file->getType() == 'recording'){
				$filename = $file->getPathTo().'/'.$file->getFilename();
				$filename = $this->nameTest($filename,$file->getBase());
				$targetdir = dirname((string) $filename);
				if(!file_exists($filename)){
					if(!is_dir($targetdir)) {
						mkdir($targetdir,0777, true);
					}
					copy($this->tmpdir.'/files/'.$file->getPathTo().'/'.$file->getFilename(),$filename);
					 $this->log(sprintf(_("File Restored %s"), $filename),'INFO');
				} else {
					$this->log(sprintf(_("Same file exists  %s"), $filename),'INFO');
				}
			}
		}
		foreach($configs['data'] as $config) {
			$recording = $this->FreePBX->Recordings->getRecordingById($config['id']);
			$files = array_keys($config['files']);
			$files = implode('&', $files);
			if(empty($recording)){
				$this->FreePBX->Recordings->addRecordingWithId($config['id'],$config['displayname'],$config['description'],$files,$config['fcode'],$config['fcode_pass']);
			}
			if(!empty($recording)){
				$this->FreePBX->Recordings->updateRecording($config['id'],$config['displayname'],$config['description'],$files,$config['fcode'],$config['fcode_pass']);
			}
		}
		$this->importFeatureCodes($configs['features']);
	}
	public function nameTest($path,$var){
		$sysPath = $this->FreePBX->Config->get($var);
		if(!$sysPath){
			return $path;
		}
		$file = basename((string) $path);
		$pathArr = explode('/',(string) $path);
		$i = array_search('sounds',$pathArr,true);
		$pathArr = array_slice($pathArr,$i);
		return $sysPath.'/'.implode('/',$pathArr);
	}

	public function processLegacy($pdo, $data, $tables, $unknownTables) {
		$this->restoreLegacyDatabase($pdo);
		$this->restoreLegacyFeatureCodes($pdo);
		$soundsDir = $this->FreePBX->Config->get('ASTVARLIBDIR').'/sounds';
		$this->log(_("Restoring sound files from Legacy Backup path : /var/lib/asterisk/sounds"));
		if(!file_exists($this->tmpdir.'/var/lib/asterisk/sounds')) {
			$this->log(_("Asterisk Sounds folder NOT found on Legacy backup !"));
			return;
		}
		$finder = new Finder();
		$fileSystem = new Filesystem();
		foreach ($finder->in($this->tmpdir.'/var/lib/asterisk/sounds') as $item) {
			$this->log("process ".$item->getPathname());
			/* Special case**
			   when files which were created as symbolic link, in the backup it comes as directory
			   And while copying  we are getting  errors .
			   to fix that issue using this logic to skip the files 
			  File which are considered as directory and name ending with .gsm Or .wav Or .sln Or .sln16
			   */
			if($item->isDir() && (str_ends_with((string) $item->getPathname(), '.g722') || str_ends_with((string) $item->getPathname(), '.ulaw') || str_ends_with((string) $item->getPathname(), '.alaw') || str_ends_with((string) $item->getPathname(), '.sln16') || str_ends_with((string) $item->getPathname(), '.sln') || str_ends_with((string) $item->getPathname(), '.gsm') || str_ends_with((string) $item->getPathname(), '.wav'))) {
				continue;
			}
			if($item->isDir()) {
				$fileSystem->mkdir($soundsDir.'/'.$item->getRelativePathname());
				continue;
			}
			$fileSystem->copy($item->getPathname(), $soundsDir.'/'.$item->getRelativePathname(), true);
		}
	}
}
