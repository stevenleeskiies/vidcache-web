<?php
namespace Vidcache\Client;
use \LSS\Config;
use \LSS\Db;
use \LSS\Url;

abstract class FS {

	public static function fetchFolderById($folder_id){
		return Db::_get()->fetch(
			'SELECT * FROM `folders` WHERE `folder_id` = ?'
			,array($folder_id)
		);
	}

	public static function fetchFileById($file_id){
		return Db::_get()->fetch(
			'SELECT f.* FROM `files` AS f'
			.' WHERE f.file_id = ?'
			,array($file_id)
		);
	}

	public static function fetchFolderByPath($path){
		return Db::_get()->fetch(
			'SELECT * FROM `folders` WHERE `path_hash` = ?'
			,array(md5($path))
		);
	}

	public static function fetchFoldersByParentDataTables($columns,$where,$order,$limit,$path,$show_hidden){
		$folder = self::fetchFolderByPath(rtrim($path,'/'));
		if(!$show_hidden){
				$where_hidden[] = ' AND name NOT LIKE ?';
				$where_hidden[] = array('.%');
		} else {
				$where_hidden = array('',array());
		}
		$sql =  ' SELECT SQL_CALC_FOUND_ROWS'
				.' *, "folder" AS `type`'
				.' FROM `folders` '
				.' '.(!empty($where[0]) ? $where[0].' AND ' : 'WHERE ').' `parent_folder_id` = ?'.$where_hidden[0]
				.$order
				.$limit;
		$vars = array_merge($where[1],array($folder['folder_id']),$where_hidden[1]);
		$result = Db::_get()->fetchAll($sql,$vars);
		// var_dump($result);
		$count_result = Db::_get()->fetch('SELECT FOUND_ROWS() AS `row_count`');
		$count_total = Db::_get()->fetch(
			'SELECT count(*) AS `row_count` FROM `folders` WHERE `parent_folder_id` = ?'
			,array($folder['folder_id'])
		);
		return array($result,$count_result['row_count'],$count_total['row_count']);
	}

	public static function fetchFilesByParentDataTables($columns,$where,$order,$limit,$path,$show_hidden){
		$folder = self::fetchFolderByPath(rtrim($path,'/'));
		if(!$show_hidden){
				$where_hidden[] = ' AND f.name NOT LIKE ?';
				$where_hidden[] = array('.%');
		} else {
				$where_hidden = array('',array());
		}
		$sql =	 ' SELECT SQL_CALC_FOUND_ROWS'
				.'	f.*, f.handle AS `file_handle`'
				.' FROM `files` AS f'
				.' '.(!empty($where[0]) ? $where[0].' AND ' : 'WHERE ').' f.folder_id = ?'.$where_hidden[0]
				.$order
				.$limit;
		$vars = array_merge($where[1],array($folder['folder_id']),$where_hidden[1]);
		$result = Db::_get()->fetchAll($sql,$vars);
		$count_result = Db::_get()->fetch('SELECT FOUND_ROWS() AS `row_count`');
		$count_total = Db::_get()->fetch(
			'SELECT count(*) AS `row_count` FROM `files` WHERE `folder_id` = ?'
			,array($folder['folder_id'])
		);
		return array($result,$count_result['row_count'],$count_total['row_count']);
	}

	public static function fetchFileByPath($path){
		return Db::_get()->fetch(
			'SELECT f.* FROM `files` AS f'
			.' WHERE f.path_hash = ?'
			,array(md5($path))
		);
	}

	public static function fetchFoldersByParent($path){
		$folder = self::fetchFolderByPath(rtrim($path,'/'));
		return Db::_get()->fetchAll(
			'SELECT * FROM `folders` WHERE `parent_folder_id` = ?'
			,array($folder['folder_id'])
		);
	}

	public static function fetchFilesByParent($path){
		$folder = self::fetchFolderByPath(rtrim($path,'/'));
		return Db::_get()->fetchAll(
			'SELECT f.* FROM `files` AS f'
			.' WHERE f.folder_id = ?'
			,array($folder['folder_id'])
		);
	}

	public static function fetchFileByHandle($handle){
		return Db::_get()->fetch(
			'SELECT f.* FROM `files` AS f'
			.' WHERE f.handle = ?'
			,array($handle)
		);
	}

	public static function fetchFileByHandleOrEmbedHandle($handle){
		return Db::_get()->fetch(
			'SELECT f.* FROM `files` AS f'
			.' WHERE f.handle = ? OR f.embed_handle = ?'
			,array($handle,$handle)
		);
	}

	public static function fetchFilesByHandleOrEmbedHandle($handle){
		return Db::_get()->fetchAll(
			'SELECT f.* FROM `files` AS f'
			.' WHERE f.handle = ? OR f.embed_handle = ?'
			,array($handle,$handle)
		);
	}

	public static function fetchFileMapByHash($hash){
		return Db::_get()->fetch(
			'SELECT * FROM `file_map` WHERE `hash` = ?'
			,array($hash)
		);
	}

	public static function fetchFileMapBySource($source,$id){
		return Db::_get()->fetch(
			'SELECT * FROM `file_map` WHERE `ext_source` = ? AND `ext_id` = ?'
			,array($source,$id)
		);
	}

	public static function countFilesByFolderPath($path){
		return count(self::fetchFilesByParent($path));
	}

	public static function fetchLastUpdateStamp(){
		$files = Db::_get()->fetch('SELECT IFNULL(`updated`,0) AS `updated` FROM `files` ORDER BY `updated` DESC LIMIT 1');
		$folders = Db::_get()->fetch('SELECT IFNULL(`updated`,0) AS `updated` FROM `folders` ORDER BY `updated` DESC LIMIT 1');
		if($folders['updated'] > $files['updated']) return $folders['updated'];
		return $files['updated'];
	}

	public static function folderUpdateByPath($path,$data=array()){
		if(!isset($data['updated'])) $data['updated'] = time();
		return Db::_get()->update('folders','path_hash',md5($path),$data);
	}

	public static function fileUpdateByPath($path,$data=array()){
		if(!isset($data['updated'])) $data['updated'] = time();
		return Db::_get()->update('files','path_hash',md5($path),$data);
	}

	//recursively create a folder tree from a path
	public static function folderCreate($path){
		//loop through the path and create folder entries as needed
		$base_path = null;
		foreach(explode('/',$path) as $part){
			if(empty($part)) continue;
			$base_path .= '/'.$part;
			$folder = self::fetchFolderByPath($base_path);
			if(!$folder){
				$folder['folder_id'] = Db::_get()->insert('folders',array(
					 'parent_folder_id'			=>	self::fetchFolderByPath(dirname($base_path))['folder_id']
					,'path'						=>	$base_path
					,'path_hash'				=>	md5($base_path)
					,'name'						=>	basename($base_path)
					,'created'					=>	time()
				));
			}
		}
		//return the last one
		if(isset($folder['folder_id']))
			return $folder['folder_id'];
		return null;
	}

	public static function folderDeleteByPath($path){
		if(self::countFilesByFolderPath($path) > 0)
			throw new Exception('Cannot remove folder, files still exist within');
		return Db::_get()->run('DELETE FROM `folders` WHERE `path_hash` = ?',array(md5($path)));
	}

	//takes two arguments
	//	1) the copy path
	//	2) the array from vc->pathInfo()['file']
	public static function fileCreate($path,$file){
		//create the file
		$handle = self::fileCreateHandle($file['path']);
		$file_id = Db::_get()->insert(
			'files'
			,array(
				 'folder_id'			=>	self::folderCreate(dirname($path))
				,'file_chksum'			=>	$file['chksum']
				,'handle'				=>	$handle
				,'path'					=>	$path
				,'path_hash'			=>	md5($path)
				,'name'					=>	basename($path)
				,'mime_type'			=>	(!is_null($file['mime_type']) ? $file['mime_type'] : 'application/octet-data')
				,'size'					=>	(!is_null($file['size']) ? $file['size'] : 0)
				,'hits_this_month'		=>	$file['hits_this_month']
				,'bytes_this_month'		=>	$file['bytes_this_month']
				,'hits_lifetime'		=>	$file['hits_lifetime']
				,'bytes_lifetime'		=>	$file['bytes_lifetime']
				,'created'				=>	time()
			)
			,true
		);
		return array($file_id,$handle);
	}

	public static function fileDeleteByPath($path){
		return Db::_get()->run('DELETE FROM `files` WHERE `path_hash` = ?',array(md5($path)));
	}

	public static function fileStoreEmbedHandle($handle,$path){
		return Db::_get()->update('files','path_hash',md5($path),array('embed_handle'=>$handle));
	}

	public static function fileCreateHandle($path){
		//check handles until we get a unique one
		$path_hash = md5($path);
		do {
			//create a handle nad make sure its unique
			$handle = gen_handle();
			$result = Db::_get()->fetch(
				'SELECT * FROM `files` WHERE `handle` = ?'
				,array($handle)
			);
			//if we got not result that means its unique insert it
			if(!$result) break;
			//if we got a result and its the same path return that
			if($result && $result['path_hash'] == $path_hash){
				$handle = $result['handle'];
				break;
			}
			//otherwise try again
		} while(true);
		return $handle;
	}

	public static function actionType($mime_type){
		if(in_array($mime_type,Config::get('embed','audio_types')))
			return 'listen';
		if(in_array($mime_type,Config::get('embed','video_types')))
			return 'watch';
		if(in_array($mime_type,Config::get('embed','static_types')))
			return 'view';
		return 'download';
	}

	public static function URLsByFile($file){
		$arr = array(
			//cluster urls
			 'url_server_static'		=>	self::buildClusterURL($file['file_id'],$file['file_chksum'],'static')
			,'url_server_embed'			=>	self::buildClusterURL($file['file_id'],$file['embed_handle'],'embed')
			,'url_server_download'		=>	self::buildClusterURL($file['file_id'],$file['file_chksum'],'download')
			,'url_server_stream'		=>	self::buildClusterURL($file['file_id'],$file['file_chksum'],'stream')
		);
		//add action url
		$action = self::actionType($file['mime_type']);
		$arr['url_'.$action] = $arr['url_file'] = 'http://'.$_SERVER['HTTP_HOST'].Url::client_file_view($action,$file['handle']);
		return $arr;
	}

	public static function buildClusterURL($file_id,$uri,$service_type){
		$cluster_url  = Config::get('vidcache','server_scheme');
		//add service type
		if(!is_null($service_type))
			$cluster_url .= $service_type;
		else
			$cluster_url .= 'lookup';
		//dns zone
		$cluster_url .= Config::get('vidcache','dns_zone');
		//cluster http port
		$port = Config::get('vidcache','server_port');
		if($port != '80')
			$cluster_url .= ':'.$port;
		//uri
		$cluster_url .= '/'.$uri;
		//append client_id for tracking
		$cluster_url .= '?client_file_id='.$file_id;
		return $cluster_url;
	}

}
