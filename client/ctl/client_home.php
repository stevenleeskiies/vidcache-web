<?php
use \LSS\Config;
use \LSS\DataTables;
use \LSS\Tpl;
use \LSS\Url;
use \Vidcache\Client\Session;
use \Vidcache\Client\FS;
use \Vidcache\SDK\VCFS;
use \Vidcache\SDK;
use \Vidcache\Client\DataModel\File;
use \Vidcache\Client\DataModel\Folder;

if(!Session::isLoggedIn())
	redirect(Url::login());

$client_id = Session::get('client_id');

//setup SDK and VCFS
VCFS::register();
$vc = SDK::load();
$vc->connect(Config::get('vidcache','api_key'));

//setup path info
$root_path = '/home/'.$client_id;
$path = !is_null(get('path')) ? get('path') : '';

//set the page url
$url = Url::client_home_path($path);

//make sure the root path exists
if($vc->pathExists($root_path) == 'none')
	$vc->folderCreate($root_path,true);

if(get('upload')){
	//make sure the root path exists
	if($vc->pathExists($root_path) == 'none')
		mkdir(VCFS::getPrefix().$root_path,true);
	//upload files
	$tmp_name = $_FILES['file']['tmp_name'];
	$upload_path = $root_path.$path.'/'.$_FILES['file']['name'];
	//upload the file
	move_uploaded_file($tmp_name,VCFS::getPrefix().$upload_path);
	//get info about the file
	$info = $vc->pathInfo($upload_path);
	//store the new file
	list($file_id,$file_handle) = FS::fileCreate($upload_path,$info['file']);
	//grab the mime type
	$mime_type = $info['file']['mime_type'];
	//check our mimetype
	if(in_array($mime_type,Config::get('embed','types'))){
		$preview_path = null;
		//videos are a bit different and need a thumbnail first
		if(in_array($mime_type,Config::get('embed','video_types'))){
			//create the thumbnail
			try {
				$rv = $vc->pathGenPreview($upload_path);
				$preview_path = $rv['preview_path'];
				//store the preview
				$preview_info = $vc->pathInfo($preview_path);
				list($preview_file_id,$preview_file_handle) = FS::fileCreate($preview_path,$preview_info['file']);
			} catch(Exception $e){
				//ignore it
			}
		}
		//publish it
		$embed_tpl_handle = Config::get('vidcache','embed_tpl_handle');
		if(!is_null(Session::get('embed_tpl_override')))
			$embed_tpl_handle = Session::get('embed_tpl_override');
		$rv = $vc->pathPublish($upload_path,$preview_path,$embed_tpl_handle);
		FS::fileStoreEmbedHandle($rv['embed_handle'],$upload_path);
	}
	//store the file internally
	list($file_id,$file_handle) = FS::fileCreate($upload_path,$info['file']);
	echo 'success';
	exit;
}

//create folder
if(post('create_folder')){
	try {
		$new_folder = $root_path.$path.'/'.post('folder');
		//create folder upstream
		mkdir(VCFS::getPrefix().$new_folder);
		//create folder locally
		$rv = FS::folderCreate($new_folder);
		alert('Folder created successfully',true,true);
		redirect(Url::client_home_path($path));
	} catch(Exception $e){
		alert($e->getMessage(),false);
	}
}

//delete files / folders
if(post('action') == 'delete'){
	try {
		$files = post('file');
		$folders = post('folder');
		if(!is_null($files) && is_array($files) && is_null(post('confirm_file_deletion')))
			throw new Exception('Files selected for deletion but not confirmed');
		if(!is_null($folders) && is_array($folders) && is_null(post('confirm_folder_deletion')))
			throw new Exception('Folders selected for deletion but not confirmed');
		//delete files
		if(is_array($files)){
			foreach($files as $file_id){
				$file = FS::fetchFileById($file_id);
				$delete_path = $file['path'];
				$vc->pathDelete($delete_path);
				FS::fileDeleteByPath($delete_path);
			}
		}
		//delete folders
		if(is_array($folders)){
			foreach($folders as $folder_id){
				$folder = FS::fetchFolderById($folder_id);
				$delete_path = $folder['path'];
				$vc->pathDelete($delete_path,true);
				FS::folderDeleteByPath($delete_path);
			}
		}
		alert('File(s) and/or Folder(s) deleted successfully!',true,true);
		redirect(Url::client_home_path($path));
	} catch(Exception $e){
		alert($e->getMessage(),false);
	}
}

if(post('action') == 'move'){
	try {
		$dest = post('destination_path');
		$vc->pathMove(post('file'),post('folder'),$root_path.$dest);
		alert('File(s) and/or Folder(s) moved successfully',true,true);
		redirect(Url::client_home_path($dest));
	} catch(Exception $e){
		alert($e->getMessage(),false);
	}
}

if(post('update_hidden')){
	$_SESSION['show_hidden_objects'] = post('show_hidden') ? true : false;
}
$show_hidden = session('show_hidden_objects');

//data table loading
if(get('datatables') == 'true'){
	$files = DataTables::_get()
		->setColumns(array('checkbox','icon','name','mime_type','size','hits_lifetime','bytes_this_month','created'))
		->setDataCallback('\Vidcache\Client\FS::fetchFilesByParentDatatables',$root_path.$path,$show_hidden)
		->setDataModel('\Vidcache\Client\DataModel\File')
		->setupFromRequest()
		->process()
		->getResult();
	$folders = DataTables::_get()
		->setColumns(array('checkbox','icon','name','type','size','hits_lifetime','bytes_this_month','created'))
		->setDataCallback('\Vidcache\Client\FS::fetchFoldersByParentDatatables',$root_path.$path,$show_hidden)
		->setDataModel('\Vidcache\Client\DataModel\Folder')
		->setupFromRequest()
		->process()
		->getResult();
	//build merged result
	$result = $files;
	$result['iTotalRecords'] += $folders['iTotalRecords'];
	$result['iTotalDisplayRecords'] += $folders['iTotalDisplayRecords'];
	$result['aaData'] = array_merge($folders['aaData'],$result['aaData']);
	echo json_encode($result);
	exit;
}



$params = array();

//setup basic params
$params['is_show_hidden'] = $show_hidden;
$params['url_action'] = $url;
$params['url_action_upload'] = $params['url_action'].'&upload=true';
$params['files'] = $params['folders'] = array();

//build the breadcrumb path
$params['path'] = array();
$params['path'][] = array('url'=>Url::client_home(),'name'=>'Root');
$built = '';
foreach(explode('/',ltrim($path,'/')) as $v){
	if(empty($v)) continue;
	$built .= '/'.$v;
	$params['path'][] = array('url'=>Url::client_home_path($built),'name'=>$v);
	
}
unset($built);

//output the template
Tpl::_get()->output('client_home',$params);
