<?php
namespace App\Controller;
use App;
use Swoole;

require_once APPSPATH.'/classes/WeiboOAuth.php';
require_once APPSPATH.'/classes/qqoauth.func.php';

class Page extends App\FrontPage
{
	public $pagesize = 10;
	function __construct($swoole)
	{
		parent::__construct($swoole);
	}

    function verify()
    {
        session();
        $this->swoole->http->header('Content-Type', 'image/png');
        Swoole\Image::verifycode_gd();
    }

    function test2()
    {
        session();
        $_SESSION['test'] =1 ;
        var_dump($_SESSION);
    }

	function oauth()
	{
		session();
		if(empty($_GET['s']) or $_GET['s']=='sina')
		{
			$conf = $this->config['oauth']['weibo'];
			$oauth = new \WeiboOAuth($conf['appid'], $conf['skey']);
			$keys = $oauth->getRequestToken();
			$_SESSION['oauth_keys'] = $keys;
			$_SESSION['oauth_serv'] = 'sina';
			$login_url = $oauth->getAuthorizeURL($keys['oauth_token'],false,WEBROOT.'/page/oauth_callback/');
			$this->swoole->http->redirect($login_url);
		}
		elseif($_GET['s']=='qq')
		{
			
			$conf = $this->config['oauth']['qq'];
			$oauth = new \QQOAuth($conf['APP_ID'], $conf['APP_KEY']);
			$token = $oauth->getRequestToken();
			$_SESSION['oauth_keys'] = $token;
			$_SESSION['oauth_serv'] = 'qq';
			$login_url = $oauth->getAuthorizeURL($token,WEBROOT.'/page/oauth_callback/');
            $this->swoole->http->redirect($login_url);
		}
	}
	function oauth_callback()
	{
		session();
		if($_SESSION['oauth_serv']=='sina')
		{
			
			$conf = $this->config['oauth']['weibo'];
			$oauth = new \WeiboOAuth($conf['appid'], $conf['skey'], $_SESSION['oauth_keys']['oauth_token'], $_SESSION['oauth_keys']['oauth_token_secret']);
			$_SESSION['last_key'] = $oauth->getAccessToken($_REQUEST['oauth_verifier']);

			$client = new \WeiboClient($conf['appid'], $conf['skey'],$_SESSION['last_key']['oauth_token'],$_SESSION['last_key']['oauth_token_secret']);
			$userinfo = $client->verify_credentials();
			if(!isset($userinfo['id']))
            {
                var_dump($conf, $_SESSION);
                return "请求错误:".var_export($userinfo, true);
            }
			$model = createModel('UserInfo');
			$username = 'sina_'.$userinfo['id'];
			$u = $model->get($username,'username')->get();
			//不存在，则插入数据库
			if(empty($u))
			{
				$u['username'] = $username;
				$u['nickname'] = $userinfo['name'];
				$u['avatar'] = $userinfo['profile_image_url'];
				list($u['province'],$u['city']) = explode(' ',$userinfo['location']);
				//插入到表中
				$u['id'] = $model->put($u);
			}
			//写入SESSION
			$_SESSION['isLogin'] = 1;
			$_SESSION['user_id'] = $u['id'];
			$_SESSION['user'] = $u;
			$this->setLoginStat();
            $this->swoole->http->edirect(WEBROOT."/person/index/");
		}
		elseif($_SESSION['oauth_serv']=='qq')
		{
            $conf = $this->swoole->config['oauth']['qq'];
			
			$oauth = new \QQOAuth($conf['APP_ID'], $conf['APP_KEY']);
			$oauth->getAccessToken($_GET['oauth_token'], $_SESSION['oauth_keys']['oauth_token_secret'], $_GET['oauth_vericode']);

			$username = $oauth->access_token['openid'];
			$model = createModel('UserInfo');
			$u = $model->get($username,'username')->get();
			//不存在，则插入数据库
			if(empty($u))
			{
				$user = $oauth->api_get('user/get_user_info');
				if(empty($user)) return Swoole\JS::js_back("请求错误");

				$u['username'] = $username;
				$u['nickname'] = $user['nickname'];
				$u['avatar'] = $user['figureurl_2'];
				//插入到表中
				$u['id'] = $model->put($u);
			}
			//写入SESSION
			$_SESSION['isLogin'] = 1;
			$_SESSION['user_id'] = $u['id'];
			$_SESSION['user'] = $u;
			$this->setLoginStat();
            $this->swoole->http->redirect(WEBROOT."/person/index/");
		}
	}
	function flist()
	{
		//Error::dbd();
		//查询根分类
		$ftype = $this->swoole->model->CmsType->get($_GET['f'],'name')->get();

		//查询相关链接
		$param_rel['limit'] = 10;
		$param_rel['gfid'] = $ftype['id'];
		$rel_news = $this->swoole->model->CmsNews->gets($param_rel);
		$this->swoole->tpl->assign('rel_news',$rel_news);
		$this->swoole->tpl->assign('rel','rel');//标识为有相关链接的页面

		//查询新闻动态分类
		$param['gfid']  = $ftype['id'];
		$param['order'] = 'id asc';
		$type = $this->swoole->model->CmsType->gets($param);

		foreach($type as $key => &$val){
			$val['title'] = $val['typename'];
		}

		$gets['limit'] = 6;
		$gets['select'] = 'id,ftitle,addtime';
		foreach($type as $key => &$val)
		{
			$gets['tid'] = $val['id'];
			$val['list'] = $this->swoole->model->CmsNews->gets($gets);
			$val['stitle'] = $val['typename'];
			$val['tid'] = $val['id'];
		}
		$this->swoole->tpl->assign('ftype',$ftype);
		$this->swoole->tpl->assign('pagelist',$type);
		$this->swoole->tpl->assign('ltitle',$ftype['typename']);
		$this->swoole->tpl->display('page_news_index.html');
	}
	function detail()
	{
		$pagenews = $this->swoole->model->CmsNews->get((int)$_GET['d'])->get();
		//查询根分类
		$ftype = $this->swoole->model->CmsType->get($pagenews['gfid'])->get();
		$this->swoole->tpl->assign('ftype',$ftype);

		//查询相关链接
		$param_rel['limit'] = 10;
		$param_rel['gfid'] = $ftype['id'];
		$rel_news = $this->swoole->model->CmsNews->gets($param_rel);
		$this->swoole->tpl->assign('rel_news',$rel_news);
		$this->swoole->tpl->assign('rel','rel');//标识为有相关链接的页面

		//查询新闻动态分类
		$param['fid']  = $ftype['id'];
		$param['order'] = 'id asc';
		$type = $this->swoole->model->CmsType->gets($param);

		foreach($type as $key => &$val){
			$val['title'] = $val['typename'];
		}
		$this->swoole->tpl->assign('pagelist',$type);

		$this->swoole->tpl->assign('ftitle',$pagenews['ftitle']);
		#######为了兼容标题硬性修改##########
		$newst = $this->swoole->model->CmsType->get($pagenews['fid'])->get();
		$newst['title'] = $newst['typename'];
		$newst['content'] = $pagenews['content'];

		$news['title'] = $typename;
		$news['descript'] = $typename;
		$news['name'] = 'news';
		$this->swoole->tpl->assign('news',$news);
		$this->swoole->tpl->assign('page',$newst);
		#######################
		$this->swoole->tpl->display('page_news_detail.html');
	}

	function index()
	{
		if(empty($_GET['p']) or $_GET['p']=='index')
		{
			$_user = createModel('UserInfo');
			//微博客列表
			$this->getMblogs(14);

			$gets['select'] = 'id,title,cname,cid,addtime';
			$gets['limit'] = 10;
			$gets['fid'] = 9;
			$model = createModel('News');
			$list = $model->gets($gets);
			
			$userlist = $this->getActiveUsers(50);
			$this->swoole->tpl->assign('userlist', $userlist);
			$this->swoole->tpl->assign('list', $list);
			$this->swoole->tpl->display('index.html');
		}
		else
		{
			$page = $_GET['p'];
			$model = createModel('Cpage');
			$det = $model->get($page,'pagename');
			$this->swoole->tpl->assign('det',$det);
			$this->swoole->tpl->display('index_page.html');
		}
	}
	/**
	 * 个人用户登录
	 * @return unknown_type
	 */
	function login()
	{
		session();
		$auth = new Swoole\Auth($this->swoole->db,'user_login');
		$refer = isset($_GET['refer'])?$_GET['refer']:WEBROOT.'/person/index/';
		if ($auth->isLogin())
		{
            $this->swoole->http->redirect($refer);
		}
		if(isset($_POST['username']) and $_POST['username']!='')
		{
			if(!isset($_POST['authcode']) or strtoupper($_POST['authcode'])!==$_SESSION['authcode'])
			{
				return Swoole\JS::js_back('验证码错误！');
			}
			$_POST['username'] = strtolower(trim($_POST['username']));
			$_POST['password'] = trim($_POST['password']);

			$password = Swoole\Auth::mkpasswd($_POST['username'],$_POST['password']);
			if($auth->login($_POST['username'],$password,isset($_POST['auto'])?1:0))
			{
				$userinfo = $this->swoole->model->UserInfo->get($_SESSION['user_id'])->get();
				$_SESSION['user'] = $userinfo;
				$this->setLoginStat();
                $this->swoole->http->redirect($refer);
			}
			else
			{
				return Swoole\JS::js_goto('用户名或密码错误！','/page/login/');
			}
		}
		else
        {
			$this->swoole->tpl->display();
		}
	}
	function logout()
	{
		session();
        Swoole\Auth::logout();
		$this->swoole->http->redirect('/page/login/');
	}
	function register()
	{
		if ($_POST)
		{
			Swoole::$php->session->start();
            if (!isset($_POST['authcode']) or strtoupper($_POST['authcode']) !== $_SESSION['authcode'])
            {
                Swoole\JS::js_back('验证码错误！');
                exit;
            }
			if ($_POST['password']!==$_POST['repassword'])
			{
				Swoole\JS::js_back('两次输入的密码不一致！');
				exit;
			}
			if (empty($_POST['nickname']))
			{
				Swoole\JS::js_back('昵称不能为空！');
				exit;
			}
			if (empty($_POST['sex']))
			{
				Swoole\JS::js_back('性别不能为空！');
				exit;
			}
			$userInfo = createModel('UserInfo');
			$login['email'] = trim($_POST['email']);
			if ($userInfo->exists($login['email']))
			{
				Swoole\JS::js_back('已存在此用户，同一个Email不能注册2次！');
				exit;
			}

			$login['password'] = Swoole\Auth::mkpasswd($login['email'],$_POST['password']);
			$login['username'] = $login['email'];
//			$login['reg_ip'] = Swoole\Http::getIP();
			$login['nickname'] = $_POST['nickname'];
			$login['sex'] = (int)$_POST['sex'];
			//$login['skill'] = implode(',',$_POST['skill']);
			// $login['php_level'] = (int)$_POST['php_level'];
			$login['lastlogin'] = date('Y-m-d h:i:s');
			$uid = $userInfo->put($login);
			$_SESSION['isLogin'] = true;
			$_SESSION['user_id'] = $uid;
			$_SESSION['user'] = $login;
			return Swoole\JS::js_goto('注册成功！','/person/index/');
		}
		else
		{
			require WEBPATH.'/dict/forms.php';
			$_forms['sex'] = Swoole\Form::radio('sex', $forms['sex']);
			//$_forms['level'] = Form::radio('php_level',$forms['level'],'');
			$this->swoole->tpl->assign('forms',$_forms);
			$this->swoole->tpl->display();
		}
	}
	function chatroom()
	{
		session();
        Swoole\Auth::$login_url = '/page/login/?';
        Swoole\Auth::login_require();
		$userInfo = createModel('UserInfo');
		$this->swoole->tpl->assign('user',$userInfo->get($_SESSION['user_id'])->get());
		$this->swoole->tpl->display();
	}
	/**
	 * 忘记密码
	 * @return unknown_type
	 */
	function forgot()
	{
		if($_POST)
		{
			$gets['realname'] = $_POST['realname'];
			$gets['username'] = $_POST['email'];
			$gets['mobile'] = $_POST['mobile'];
			$gets['select'] = 'id';
			$ul = $this->model->UserInfo->gets($gets);
			if(count($ul)!=0)
			{
				$password = App\Func::randomkeys(6);
				$this->model->UserInfo->set($ul[0]['id'],array('password'=>Auth::mkpasswd($gets['username'],$password)));
                App\Func::success('找回成功！','您的新密码是 <span style="color:#fe7e00;">'.$password.'</a>');
			}
		}
		else
		{
			$this->swoole->tpl->display();
		}
	}

	function test()
	{
		$me = createModel('Me');
		if($_POST)
		{
			if(!$me->checkForm($_POST,'add',$error))
			{
				Swoole\JS::js_back($error);
				return;
			}
			echo 'ok';
		}
		else
		{
			$form = $me->getForm();
			$this->swoole->tpl->assign('head', Swoole\Form::head('me_add','post','',true));
			$this->swoole->tpl->assign('js', Swoole\Form::js('me_add'));
			$this->swoole->tpl->assign('form',$form);
			$this->swoole->tpl->display('test.html');
		}
	}

	private function fulltext($q,$page)
	{
		$cl = new SphinxClient ();
		$cl->SetServer('localhost',9312);
		$cl->SetArrayResult(true);

		$cl->SetLimits(($page-1)*$this->pagesize,$this->pagesize);
		$res = $cl->Query($q,"news");
		$model = createModel('News');

		foreach($res['matches'] as $m) $ids[] = $m['id'];
		if(empty($ids)) $res['list'] = array();
		else
		{
			$gets['in'] = array('id',implode(',',$ids));
			$gets['limit'] = $this->pagesize;
			$gets['order'] = '';
			$gets['select'] = "id,title,addtime";
			$list = $model->gets($gets);
			$res['list'] = $list;
		}
		return $res;
	}

	function search()
	{
		$keyword = mb_substr(trim($_GET['k']),0,32);
		if(empty($keyword))
		{
			Swoole\JS::js_back('关键词不能为空！');
			exit;
		}
		$page = empty($_GET['page'])?1:(int)$_GET['page'];
		$res = $this->fulltext($keyword,$page);
		$pager = new Swoole\Pager(array('page'=>$page,'perpage'=>$this->pagesize,'total'=>$res['total']));
		$this->swoole->tpl->assign('pager', array('total'=>$pager->total,'render'=>$pager->render()));
		$this->swoole->tpl->assign('forms', $_forms);
		$this->swoole->tpl->assign("list", $res['list']);
		$this->swoole->tpl->display();
	}

	function guestbook()
	{
		if($_POST)
		{
			if(empty($_POST['realname']))
			{
				Swoole\JS::js_back('姓名不能为空！');
				exit;
			}
			if(empty($_POST['mobile']))
			{
				Swoole\JS::js_back('电话不能为空！');
				exit;
			}
			unset($_POST['x'],$_POST['y']);
			$_POST['product'] = implode(',',$_POST['product']);
			$_POST['source'] = implode(',',$_POST['source']);
			$php->model->Guestbook->put($_POST);
			Swoole\JS::js_goto('注册成功！','guestbook.php');
		}

		if (!empty($_GET['id']))
		{
			$gb = $php->model->Guestbook->get($_GET['id'])->get();
			$php->tpl->assign('gb',$gb);
			$php->tpl->display('guestbook_detail.html');
		}
		else
		{
			require 'dict/forms.php';
			$pager = null;
			$gets['page'] = empty($_GET['page'])?1:$_GET['page'];
			$gets['pagesize'] =  12;
			$gets['select'] = "id,username,title,addtime";
			$gets['where'][] = "reply!=''";
			$list = $php->model->Guestbook->gets($gets,$pager);

			$_forms['title'] = Swoole\Form::radio('title', $forms['title'],null,true,array('empty'=>'请选择称谓'));
			$_forms['age'] = Swoole\Form::select('age', $forms['age'],null,true,array('empty'=>'请选择年龄阶段'));
			$_forms['ctime'] = Swoole\Form::select('ctime',$forms['ctime'],null,true,array('empty'=>'请选择方便沟通的时间'));
			$_forms['product'] = Swoole\Form::checkbox('product',$forms['product'],null,true);
			$_forms['source'] = Swoole\Form::checkbox('source',$forms['source'],null,true);

			$pager = array('total'=>$pager->total,'render'=>$pager->render());
			$php->tpl->assign('pager',$pager);
			$php->tpl->assign('forms',$_forms);
			$php->tpl->assign("list",$list);
			$php->tpl->display('guestbook.html');
		}
	}
	function user()
	{
		if(empty($_GET['uid'])) exit('Uid is empty');
		$uid = (int)$_GET['uid'];
		$this->userinfo($uid);
		$this->getMblogs(10,$uid);
		$this->swoole->tpl->display();
	}

	private function setLoginStat()
	{
		$tm = time();
        Swoole\Cookie::set('uname', $_SESSION['user']['nickname'], $tm+86400*30,'/');
        Swoole\Cookie::set('uid', $_SESSION['user_id'], $tm+86400*30,'/');
	}
}
