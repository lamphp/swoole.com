<?php
namespace App\Controller;
use App;
use Swoole;

class Ask extends Swoole\Controller
{
    function index()
    {
        $ask = createModel('AskSubject');
        $gets['order'] = 'id desc';
        $gets['limit'] = 10;
        $gets['select'] = 'id,cname,cid,title,addtime,gold,qcount';
        $gets['mstatus'] = 0;
        $list['f1'] = $ask->gets($gets);

        $gets['mstatus'] = 2;
        $list['f3'] = $ask->gets($gets);

        unset($gets['mstatus']);
        $gets['order'] = 'gold desc,id desc';
        $gets['where'] = 'mstatus!=2';
        $list['f2'] = $ask->gets($gets);

        $this->swoole->tpl->assign('list',$list);
        $this->swoole->tpl->display();
    }

    function detail()
    {
        session();
        if(empty($_GET['aid'])) exit;
        $_user = createModel('UserInfo');
        $_reply = createModel('AskReply');

        $aid = (int)$_GET['aid'];
        $ask = createModel('AskSubject')->get($aid);
        if(empty($ask->_data))
        {
        	Swoole\Http::header("HTTP/1.1 404 Not Found");
        	return Swoole\Error::info("Page not found","");
        }

		$ask->lcount++;
		$ask->save();

        $timeout['day'] = intval(($ask['expire']-time())/86400);
        $timeout['hour'] = intval(($ask['expire']-time()-$timeout['day']*86400)/3600);

        $user = $_user->get($ask['uid'])->get();
        $content = createModel('AskContent')->get($aid)->get();

        $gets['aid'] = $aid;
        $gets['select'] = $_reply->table.'.id as id,uid,sex,best,content,nickname,avatar,addtime';
        $gets['order'] = 'best desc,'.$_reply->table.'.id asc';
        $gets['leftjoin'] = array($_user->table,$_user->table.'.id='.$_reply->table.'.uid');
        $gets['pagesize'] = 10;
        $gets['page'] = empty($_GET['page'])?1:(int)$_GET['page'];
        $replys = $_reply->gets($gets,$pager);

        $if_vote=true;
        if($_SESSION['isLogin'])
        {
            $vote = $this->swoole->db->query("select count(*) as c from ask_vote where aid=$aid and uid={$_SESSION['user_id']} limit 1")->fetch();
            if($vote['c']>0) $if_vote=false;
        }
        $this->swoole->tpl->assign('if_vote',$if_vote);
        $this->swoole->tpl->assign('if_vote',$if_vote);
        $this->swoole->tpl->assign('expire',$timeout);
        $this->swoole->tpl->ref('user',$user);
        $this->swoole->tpl->ref('ask',$ask->get());
        $this->swoole->tpl->ref('content',$content);
        $this->swoole->tpl->ref('replys',$replys);
        if($pager->totalpage>1) $this->swoole->tpl->ref('pager',$pager->render());
        $this->swoole->tpl->display();
    }
    function reply()
    {
        session();
        if(!$_SESSION['isLogin'])
        {
            return Swoole_js::echojs("if(confirm('您还没有登录，是否调整到登录页面(请首先复制您的回答内容)？')) window.parent.location.href='/page/login/?'");
        }
        $this->swoole->autoload('user');
        if(!empty($_POST['reply']))
        {
            $answer['content'] = $_POST['reply'];
            $answer['uid'] = $this->swoole->user->getUid();
            $user = createModel('UserInfo')->get($answer['uid']);

            $answer['aid'] = (int)$_POST['aid'];
            $ask = createModel('AskSubject')->get($answer['aid']);
            //答案数量加1
            $ask->qcount +=1;
            //如果是未答状态，则设置为已答
            if($ask->mstatus==0) $ask->mstatus=1;
            $ask->save();

            //为用户增加积分，回答即加5分
            $user->gold +=5;
            $user->save();
            
            Api::sendmail($ask['uid'], $answer['uid'], "【系统】".$user['nickname']."回答了你的提问.({$ask['title']})", $answer['content']);

            createModel('AskReply')->put($answer);
            return Swoole_js::alert('发布成功').Swoole_js::echojs('window.parent.location.href = window.parent.location.href;');
        }
    }
}