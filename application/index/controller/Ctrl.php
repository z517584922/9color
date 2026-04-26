<?php

namespace app\index\controller;

use think\Controller;
use think\Request;
use think\Db;

class Ctrl extends Base
{
      //钱包页面
    public function wallet()
    {
        $balance = db('xy_users')->where('id',session('user_id'))->value('balance');
        $this->assign('balance',$balance);
        $balanceT = db('xy_convey')->where('uid',session('user_id'))->where('status',1)->sum('commission');
        $this->assign('balance_shouru',$balanceT);

        //收益
        $startDay = strtotime( date('Y-m-d 00:00:00', time()) );
        $shouyi = db('xy_convey')->where('uid',session('user_id'))->where('addtime','>',$startDay)->where('status',1)->select();

        //充值
        $chongzhi = db('xy_recharge')->where('uid',session('user_id'))->where('addtime','>',$startDay)->where('status',2)->select();

        //提现
        $tixian = db('xy_deposit')->where('uid',session('user_id'))->where('addtime','>',$startDay)->where('status',1)->select();

        $this->assign('shouyi',$shouyi);
        $this->assign('chongzhi',$chongzhi);
        $this->assign('tixian',$tixian);
        return $this->fetch();
    }


    public function recharge_before()
    {
        $pay = db('xy_pay')->where('status',1)->select();

        $this->assign('pay',$pay);
        return $this->fetch();
    }


    public function vip()
    {
        $uid = session('user_id');
        if(!$uid) $this->redirect('User/login'); 
        $this->member_level = db('xy_level')->order('level asc')->select();;
        $this->info = db('xy_users')->where('id', session('user_id'))->find();
        $this->member = $this->info;

        //var_dump($this->info['level']);die;

        $level_name = $this->member_level[0]['name'];
        $order_num = $this->member_level[0]['order_num'];
        if (!empty($this->info['level'])){
            $level_name = db('xy_level')->where('level',$this->info['level'])->value('name');
        }
        if (!empty($this->info['level'])){
            $order_num = db('xy_level')->where('level',$this->info['level'])->value('order_num');
        }
        
        $this->level_name = $level_name;
        $this->order_num = $order_num;
        $this->can_vip_info = model('admin/Users')->can_vip_info($uid);
        return $this->fetch();
    }

    /**
     * @地址      recharge_dovip
     * @说明      利息宝
     * @参数       @参数 @参数
     * @返回      \think\response\Json
     */
    public function lixibao()
    {
        
        $uid = session('user_id');
        if(!$uid) $this->redirect('User/login'); 
        $this->assign('title',lang('利息宝'));
        $uinfo = db('xy_users')->field('username,tel,level,id,headpic,balance,freeze_balance,lixibao_balance,lixibao_dj_balance')->find(session('user_id'));

        $this->assign('ubalance',$uinfo['balance']);
        $this->assign('balance',$uinfo['lixibao_balance']);
        $this->assign('balance_total',$uinfo['lixibao_balance'] + $uinfo['lixibao_dj_balance']);
        $balanceT = db('xy_lixibao')->where('uid',session('user_id'))->where('status',1)->where('type',3)->sum('num');

        $balanceT = db('xy_balance_log')->where('uid',session('user_id'))->where('status',1)->where('type',23)->sum('num');

        $yes1 = strtotime( date("Y-m-d 00:00:00",strtotime("-1 day")) );
        $yes2 = strtotime( date("Y-m-d 23:59:59",strtotime("-1 day")) );
        $this->yes_shouyi = db('xy_balance_log')->where('uid',session('user_id'))->where('status',1)->where('type',23)->where('addtime','between',[$yes1,$yes2])->sum('num');

        $this->assign('balance_shouru',$balanceT);


        //收益
        $startDay = strtotime( date('Y-m-d 00:00:00', time()) );
        $shouyi = db('xy_lixibao')->where('uid',session('user_id'))->select();

        foreach ($shouyi as &$item) {
            $type = '';
            if ($item['type'] == 1) {
                $type = '<font color="green">'.lang("转入利息宝").'</font>';
            }elseif ($item['type'] == 2) {
                $n = $item['status'] ? lang("已到账") : lang("未到账");
                $type = '<font color="red" >'.lang("利息宝转出").'('.$n.')</font>';
            }elseif ($item['type'] == 3) {
                $type = '<font color="orange" >'.lang("每日收益").'</font>';
            }else{

            }

            $lixbao = Db::name('xy_lixibao_list')->find($item['sid']);

            $name = $lixbao['name'].'('.$lixbao['day'].lang('天').')'.$lixbao['bili']*100 .'% ';

            $item['num'] = number_format($item['num'],2);
            $item['name'] = $type.'　　'.$name;
            $item['shouxu'] = $lixbao['shouxu']*100 .'%';
            $item['addtime'] = date('Y/m/d H:i', $item['addtime']);

            if ($item['is_sy'] == 1) {
                $notice = lang('正常收益,实际收益').$item['real_num'];
            }else if ($item['is_sy'] == -1) {
                $notice = lang('未到期提前提取,未收益,手续费为').':'.$item['shouxu'];
            }else{
                $notice = lang('理财中').'...';
            }
            $item['notice'] =$notice;
        }

        $this->rililv = config('lxb_bili')*100 .'%' ;
        $this->shouyi=$shouyi;
        if(request()->isPost()) {
            return json(['code'=>0,'info'=>lang('操作'),'data'=>$shouyi]);
        }

        $lixibao = Db::name('xy_lixibao_list')->field('id,name,bili,day,min_num')->order('day asc')->select();
        $this->lixibao = $lixibao;
        $log = $this->_query('xy_lixibao')->where('uid',session('user_id'))->order('addtime desc')->page();
        return $this->fetch();
    }

    public function lixibao_ru()
    {
        $uid = session('user_id');
        $uinfo = Db::name('xy_users')->field('recharge_num,deal_time,balance,level')->find($uid);//获取用户今日已充值金额

        if(request()->isPost()){
            $price = input('post.price/d',0);
            $id = input('post.lcid/d',0);
            $yuji=0;
            if ($id) {
                $lixibao = Db::name('xy_lixibao_list')->find($id);
                if ($price < $lixibao['min_num']) {
                    return json(['code'=>1,'info'=>lang('该产品最低起投金额').$lixibao['min_num']]);
                }
                if ($price > $lixibao['max_num']) {
                    return json(['code'=>1,'info'=>lang('该产品最高可投金额').$lixibao['max_num']]);
                }
                $yuji = $price * $lixibao['bili'] * $lixibao['day'];
            }else{
                return json(['code'=>1,'info'=>lang('数据异常')]);
            }


            if ( $price <= 0 ) {
                return json(['code'=>1,'info'=>'you are sb']); //直接充值漏洞
            }
            if ($uinfo['balance'] < $price ) {
                return json(['code'=>1,'info'=>lang('可用余额不足')]);
            }
            Db::name('xy_users')->where('id',$uid)->setInc('lixibao_balance',$price);  //利息宝月 +
            Db::name('xy_users')->where('id',$uid)->setDec('balance',$price);  //余额 -

            $endtime = time() + $lixibao['day'] * 24 * 60 * 60;

            $res = Db::name('xy_lixibao')->insert([
                'uid'         => $uid,
                'num'         => $price,
                'addtime'     => time(),
                'endtime'     => $endtime,
                'sid'         => $id,
                'yuji_num'      => $yuji,
                'type'        => 1,
                'status'      => 0,
            ]);
            $oid = Db::name('xy_lixibao')->getLastInsID();
            $res1 = Db::name('xy_balance_log')->insert([
                //记录返佣信息
                'uid'       => $uid,
                'oid'       => $oid,
                'num'       => $price,
                'type'      => 21,
                'status'    => 2,
                'addtime'   => time()
            ]);
            if($res) {
                return json(['code'=>0,'info'=>lang('操作成功')]);
            }else{
                return json(['code'=>1,'info'=>lang('操作失败!请检查账号余额')]);
            }
        }

        $this->rililv = config('lxb_bili')*100 .'%' ;
        $this->yue = $uinfo['balance'];
        $isajax = input('get.isajax/d',0);
        
        if ($isajax) {
            $lixibao = Db::name('xy_lixibao_list')->field('id,name,bili,day,min_num')->select();
            $data2=[];
            $str = $lixibao[0]['name'].'('.$lixibao[0]['day'].lang('天').')'.$lixibao[0]['bili']*100 .'% ('.$lixibao[0]['min_num'].lang('起投').')';
            foreach ($lixibao as $item) {
                $data2[] = array(
                    'id'=>$item['id'],
                    'value'=>$item['name'].'('.$item['day'].lang('天').')'.$item['bili']*100 .'% ('.$item['min_num'].lang('起投').')',
                );
            }
            return json(['code'=>0,'info'=>lang('操作'),'data'=>$data2,'data0'=>$str]);
        }

        $this->libi =1;

        $this->assign('title',lang('利息宝余额转入'));
        return $this->fetch();
    }


    public function deposityj()
    {
        $num = input('post.price/f',0);
        $id = input('post.lcid/d',0);
        if ($id) {
            $lixibao = Db::name('xy_lixibao_list')->find($id);

            $res = $num * $lixibao['day'] * $lixibao['bili'];
            return json(['code'=>0,'info'=>lang('操作'),'data'=>$res]);
        }
    }

    public function lixibao_chu()
    {
        $uid = session('user_id');
        $uinfo = Db::name('xy_users')->field('recharge_num,deal_time,balance,level,lixibao_balance')->find($uid);//获取用户今日已充值金额

        if(request()->isPost()){
            $id = input('post.id/d',0);
            $lixibao = Db::name('xy_lixibao')->find($id);
            if (!$lixibao) {
                return json(['code'=>1,'info'=>lang('数据异常')]);
            }
            if ($lixibao['is_qu']) {
                return json(['code'=>1,'info'=>lang('重复操作')]);
            }

            if ($uinfo['lixibao_balance'] < $lixibao['num'] ) {
                return json(['code'=>1,'info'=>lang('可用余额不足')]);
            }
            //利息宝参数
            $lxbParam = Db::name('xy_lixibao_list')->find($lixibao['sid']);

            if($lixibao['endtime']>time())  return json(['code'=>1,'info'=>lang('未到期不可取')]);
            $issy = 0;
            if ( time() > $lixibao['endtime'] ) {
                //未到期
                $issy= 1;
                $shouxu = $lxbParam['shouxu'];
            }else{
                $issy= -1;
                $shouxu = 0;
            }

            Db::name('xy_users')->where('id',$uid)->setDec('lixibao_balance',$lixibao['num']);  //利息宝投资总额 -提现

            
            $res = Db::name('xy_lixibao')->where('id',$id)->update([
                'endtime'     => time(),
                'is_qu'      => 1,
                'is_sy'      => $issy,
                'shouxu'     =>$lixibao['num']*$shouxu,
                'real_num'  =>$lixibao['num']-$lixibao['num']*$shouxu,
            ]);


            Db::name('xy_users')->where('id',$uid)->setInc('balance',$lixibao['yuji_num']+$lixibao['num']);  //根据预计收入，增加余额
            $res1 = Db::name('xy_balance_log')->insert([
                //记录返佣信息
                'uid'       => $uid,
                'oid'       => $id,
                'num'       => $lixibao['yuji_num']+$lixibao['num'],
                'type'      => 22,
                'addtime'   => time()
            ]);

            //利息宝记录转出


            if($res) {
                return json(['code'=>0,'info'=>lang('操作成功')]);
            }else{
                return json(['code'=>1,'info'=>lang('操作失败!请检查账号余额')]);
            }

        }

        $this->assign('title',lang('利息宝余额转出'));
        $this->rililv = config('lxb_bili')*100 .'%' ;
        $this->yue = $uinfo['lixibao_balance'] ;

        $log = $this->_query('xy_lixibao')->where('uid',session('user_id'))->order('addtime desc')->page();


        return $this->fetch();
    }



    //升级vip
    public function recharge_dovip()
    {
        if(request()->isPost()){
            $level = input('post.level/d',1);

            if(!$level ) return json(['code'=>1,'info'=>lang('参数错误')]);

            $uid = session('user_id');
            $can_vip_info = model('admin/Users')->can_vip_info($uid);
            $levelinfo = db('xy_level')->where('level',$level)->field('num,auto_vip_xu_num')->find();
            if($can_vip_info['balance']>=$levelinfo['num'] & $can_vip_info['child_num']>=$levelinfo['auto_vip_xu_num']){
                $res = Db::name('xy_users')->where('id',$uid)->update(['level'=>$level]); 
                Db::name('xy_message')->insert(['uid'=>$uid,'type'=>2,'title'=>lang('系统通知'),'content'=>lang('你已达到升级标准,已完成升级'),'addtime'=>time(),'status'=>0]);
            }else if($can_vip_info['balance']>=$levelinfo['num'] & $can_vip_info['child_num']<$levelinfo['auto_vip_xu_num']){
                
                return json(['code'=>1,'info'=>lang('邀请用户不足')]);
            }else if($can_vip_info['balance']<$levelinfo['num'] & $can_vip_info['child_num']>=$levelinfo['auto_vip_xu_num']){
                
                return json(['code'=>1,'info'=>lang('用户余额不足')]);
            }else{
                
                return json(['code'=>1,'info'=>lang('升级条件不满足')]);
            }
         

           
            if($res){
                
        return json(['code'=>0,'info'=>lang('请求成功'),'data'=>[]]);
            }else{
                return json(['code'=>1,'info'=>lang('提交失败,请稍后再试')]);
            }
        }
        return json(['code'=>0,'info'=>lang('请求成功'),'data'=>[]]);
    }


    public function recharge(){
        $uid = session('user_id');
        if(!$uid) $this->redirect('User/login'); 
        $tel = Db::name('xy_users')->where('id',$uid)->value('tel');//获取用户今日已充值金额
        $this->tel = substr_replace($tel,'****',3,4);
        $this->pay = db('xy_pay')->where('status',1)->order('sort desc')->select();
        $color = sysconf('app_color');
        if($color){
            return $this->fetch('recharge-'.$color);
        }else{
            return $this->fetch('recharge-blue');
        }
    }

    public function recharge_do_before()
    {
        $num = input('post.price/f',0);
        $type = input('post.type/s','9'); // 默认设为9（客服处理）

        $uid = session('user_id');
        if(!$uid) return json(['code'=>1,'info'=>lang('请先登录')]);
        if(!$num ) return json(['code'=>1,'info'=>lang('参数错误')]);

        //时间限制 //TODO
        $res = check_time(config('chongzhi_time_1'),config('chongzhi_time_2'));
        $str = config('chongzhi_time_1').":00  - ".config('chongzhi_time_2').":00";
        if($res) return json(['code'=>1,'info'=>lang('禁止在').$str.lang('以外的时间段执行当前操作')]);

        // 获取用户信息
        $uinfo = db('xy_users')->field('tel,username')->find($uid);
        if(!$uinfo) return json(['code'=>1,'info'=>lang('用户信息不存在')]);

        // 创建充值记录
        $id = getSn('CZ'); // 生成充值订单号
        $res = db('xy_recharge')->insert([
            'id'        => $id,
            'uid'       => $uid,
            'tel'       => $uinfo['tel'],
            'real_name' => $uinfo['username'],
            'pic'       => '',
            'num'       => $num,
            'addtime'   => time(),
            'pay_name'  => 'manual_service', // 标记为客服处理
            'status'    => 1, // 待处理状态
            'type'      => 1,
            'charge'    => 0,
            'payInfo'   => '',
            'orderNo'   => '',
            'orderDate' => '',
            'notifyDate'=> date('Y-m-d H:i:s')
        ]);

        if($res){
            return json([
                'code' => 0,
                'info' => '充值请求已提交，订单号：' . $id,
                'data' => [
                    'order_id' => $id,
                    'amount' => $num
                ]
            ]);
        } else {
            return json(['code'=>1,'info'=>lang('充值请求提交失败，请稍后重试')]);
        }
    }


    public function recharge6()
    {
        $oid = input('get.oid/s','');
        $num = input('get.num/s','');
        $type = input('get.type/s','');
        $this->pay = db('xy_pay')->where('status',1)->where('name2',$type)->find();

        $pay  = $this->pay;
            $uid = session('user_id');
            $uinfo = db('xy_users')->field('pwd,salt,tel,username')->find($uid);
            if(!$num ) return json(['code'=>1,'info'=>lang('参数错误')]);

 
            //if ($num < $pay['min']) return json(['code'=>1,'info'=>lang('充值不能小于').$pay['min']]);
            //if ($num > $pay['max']) return json(['code'=>1,'info'=>lang('充值不能大于').$pay['max']]);

            $id = getSn('SY');
            $res = db('xy_recharge')
                ->insert([
                    'id'        => $id,
                    'uid'       => $uid,
                    'tel'       => $uinfo['tel'],
                    'real_name' => $uinfo['username'],
                    'pic'       => '',
                    'num'       => $num,
                    'addtime'   => time(),
                    'pay_name'  => $type
                ]);
            if($res){
                $pay['id'] = $id;
                $pay['num'] =$num;
           /*     if ($pay['name2'] == 'bipay' ) {
                    $pay['redirect'] = url('/index/Api/bipay').'?oid='.$id;
                }
                if ($pay['name2'] == 'paysapi' ) {
                    $pay['redirect'] = url('/index/Api/pay').'?oid='.$id;
                }
                return json(['code'=>0,'info'=>$pay]);
                */
            }

            else{
                return json(['code'=>1,'info'=>lang('提交失败,请稍后再试')]);
            }
        //print_r($this->pay);

        $pinfosc = explode('|',$pay['address']);
        //print_r($pinfosc);

    $result = null;

    $merchant_key = $pinfosc[1];//支付秘钥

                $server_url = $_SERVER['SERVER_NAME']?"http://".$_SERVER['SERVER_NAME']:"http://".$_SERVER['HTTP_HOST'];
                $notifyUrl = $server_url.url('/index/api/webpay_notify');
                $returnUrl = $server_url.url('/index/api/bipay_return');
                
    $postdata=array(
  "merchant_id"=>$pinfosc[0],
  "notify_url"=>$notifyUrl,
  //"page_url"=>$returnUrl,
  "order_id"=>$id,
  "pay_type"=>"101",
  "amount"=>$num
  );
	
    //$signAPI = new signapi();
	$signStr = $this->getstrs($postdata);
    //echo $signStr.'</br>';	
    
    //$sign   = $this->sign('goods_name=test&mch_id=123456789&mch_order_no=2021-04-13 17:32:28¬ify_url=http://www.baidu.com/notify_url.jsp&order_date=2021-04-13 17:32:25&pay_type=122&trade_amount=100&version=1.0','0936D7E86164C2D53C8FF8AD06ED6D09');
    //echo $sign.'</br>';
    //exit();
    
    
    $sign   = $this->sign($signStr,$merchant_key);
    //echo $sign.'</br>';
	//exit();
    //$postdata['redirect_url']  = $returnUrl;	
    //$postdata['sign_type'] = "MD5";
    $postdata['sign'] = $sign;
    //print_r($postdata);
    $postdata        = json_encode($postdata);
    //print_r($postdata);
    $ch = curl_init();    
    curl_setopt($ch,CURLOPT_URL,"https://pay.3kbpay.com/oumei/recharge"); //支付请求地址
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    //curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    //curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postdata));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);  
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postdata))
    );
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response=curl_exec($ch);

    //$res=simplexml_load_string($response);

    curl_close($ch);
    echo $response;          
        
    //exit();    
    $res = json_decode($response,true);
    //print_r($res);exit();
    if($res['status']=='0' && isset($res['data']['pay_url'])){
        header('Location:'.$res['data']['pay_url']);
    }
    else{
        $this->error(lang('提交失败,请稍后再试'));
    }
    //echo $response;          
        
        exit();
        //$num = $num.'.'.rand(10,99); //随机金额
        $info = [];//db('xy_recharge')->find($oid);
        $info['num'] = $num;//db('xy_recharge')->find($oid);
        $info['master_bank'] = config('master_bank');//银行名称
        $info['master_name'] = config('master_name');//收款人
        $info['master_cardnum'] = config('master_cardnum');//银行卡号
        $info['master_bk_address'] = config('master_bk_address');//银行地址
        $this->info = $info;


        //return $this->fetch();
    }

    public function recharge_Qea()
    {

        $num = input('get.num/s','');
        $type = input('get.type/s','');
        $uid = session('user_id');
        if(!$uid) $this->redirect('index/user/login');
        
            $uinfo = db('xy_users')->field('pwd,salt,tel,username')->find($uid);
            if(!$num ) return json(['code'=>1,'info'=>lang('参数错误')]);
            
            if($num< 100)  $this->redirect('index/ctrl/recharge'); 
            
        $startDay = strtotime( date('Y-m-d 00:00:00', time()) );

        //充值
        $ck = db('xy_recharge')->where('uid',session('user_id'))->where('addtime','>',$startDay)->count();
        if($ck>20) return json(['code'=>1,'info'=>lang('充值次数太多')]);

            $pay = db('xy_pay')->where('name2',$type)->find();
            if ($num < $pay['min']) return json(['code'=>1,'info'=>lang('充值不能小于').$pay['min']]);
            if ($num > $pay['max']) return json(['code'=>1,'info'=>lang('充值不能大于').$pay['max']]);
            //手续费设置
           //$real_num=$num-$pay['charge'];
            
            $id = getSn('SY');
            $res = db('xy_recharge')
                ->insert([
                    'id'        => $id,
                    'uid'       => $uid,
                    'tel'       => $uinfo['tel'],
                    'real_name' => $uinfo['username'],
                    'num'       => $num,
                    'addtime'   => time(),
                    'pay_name'  => $type,
                    'charge'=>$pay['charge']
                ]);
            if($res){
                $pay['id'] = $id;
                $pay['num'] =$num;
                if ($pay['name2'] == 'bipay') {
                    $pay['redirect'] = url('/index/Api/bipay').'?oid='.$id;
                    $this->redirect($pay['redirect']);
                }
                if ($pay['name2'] == 'paysapi') {
                    $pay['redirect'] = url('/index/Api/pay').'?oid='.$id;
                    $this->redirect($pay['redirect']);
                }
                
                if ($pay['name2'] == 'Qea') {
                    
                    $pay['redirect'] = url('/index/Api/qeapay').'?oid='.$id;
                    $this->redirect($pay['redirect']);
                }
                
            }else{
                    $this->redirect(url('/index/my/index'));
            }
    }


    public function getccs()
    {
        $oid = input('get.oid/s','');
        $num = input('get.num/s','');
        $type = input('get.type/s','');
        $this->pay = db('xy_pay')->where('status',1)->where('name2',$type)->find();

        $pay  = $this->pay;
            $uid = session('user_id');
            $uinfo = db('xy_users')->field('pwd,salt,tel,username')->find($uid);
            if(!$num ) return json(['code'=>1,'info'=>lang('参数错误')]);

 
            //if ($num < $pay['min']) return json(['code'=>1,'info'=>lang('充值不能小于').$pay['min']]);
            //if ($num > $pay['max']) return json(['code'=>1,'info'=>lang('充值不能大于').$pay['max']]);

            $id = getSn('SY');
  

        $pinfosc = explode('|',$pay['address']);
        //print_r($pinfosc);

    $result = null;

    $merchant_key = $pinfosc[1];//支付秘钥

                $server_url = $_SERVER['SERVER_NAME']?"http://".$_SERVER['SERVER_NAME']:"http://".$_SERVER['HTTP_HOST'];
                $notifyUrl = $server_url.url('/index/api/webpay_notify');
                $returnUrl = $server_url.url('/index/api/bipay_return');
                
    $postdata=array(
  "merchant_id"=>$pinfosc[0],
  "receive_type"=>"BANK",//\/CASH
  "receive_currency"=>"EUR",
  "receive_country"=>"ESP"
  );
	
 
	$signStr = $this->getstrs($postdata);


    $sign   = $this->sign($signStr,$merchant_key);
    //echo $sign.'</br>';
	//exit();
    //$postdata['redirect_url']  = $returnUrl;	
    //$postdata['sign_type'] = "MD5";
    $postdata['sign'] = $sign;
    //print_r($postdata);
    $postdata        = json_encode($postdata);
    //print_r($postdata);
    $ch = curl_init();    
    curl_setopt($ch,CURLOPT_URL,"https://pay.3kbpay.com/api/oumei/getservicepoint"); //支付请求地址
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    //curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    //curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postdata));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);  
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postdata))
    );
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response=curl_exec($ch);

    //$res=simplexml_load_string($response);

    curl_close($ch);
    echo $response;          
        
    exit();    
    $res = json_decode($response,true);
    //print_r($res);exit();
    if($res['status']=='0' && isset($res['data']['pay_url'])){
        header('Location:'.$res['data']['pay_url']);
    }
    else{
        $this->error(lang('提交失败,请稍后再试'));
    }
    //echo $response;          
        
        exit();
        //$num = $num.'.'.rand(10,99); //随机金额
        $info = [];//db('xy_recharge')->find($oid);
        $info['num'] = $num;//db('xy_recharge')->find($oid);
        $info['master_bank'] = config('master_bank');//银行名称
        $info['master_name'] = config('master_name');//收款人
        $info['master_cardnum'] = config('master_cardnum');//银行卡号
        $info['master_bk_address'] = config('master_bk_address');//银行地址
        $this->info = $info;


        //return $this->fetch();
    }
    
    public function recharge7()
    {
        $oid = input('get.oid/s','');
        $num = input('get.num/s','');
        $type = input('get.type/s','');
        $this->pay = db('xy_pay')->where('status',1)->where('name2',$type)->find();
        if(request()->isPost()) {
            
            $num = input('post.price/s','');
            $bank_code = input('post.bank_code/s', '');
            $acc_no = input('post.acc_no/s', '');
            $acc_name = input('post.acc_name/s', '');
            $acc_name1 = input('post.acc_name1/s', '');            
            $mobile_no = input('post.mobile_no/s', '');   
            
         $type = input('post.type/s','');
         $pay = db('xy_pay')->where('status',1)->where('name2',$type)->find();
        
            //$pay  = $this->pay;
            $uid = session('user_id');
            $uinfo = db('xy_users')->field('pwd,salt,tel,username')->find($uid);
            if(!$num ) return json(['code'=>1,'info'=>lang('参数错误')]);

 
            //if ($num < $pay['min']) return json(['code'=>1,'info'=>lang('充值不能小于').$pay['min']]);
            //if ($num > $pay['max']) return json(['code'=>1,'info'=>lang('充值不能大于').$pay['max']]);

            $id = getSn('SY');
            $res = db('xy_recharge')
                ->insert([
                    'id'        => $id,
                    'uid'       => $uid,
                    'tel'       => $mobile_no,
                    'real_name' => $acc_name,
                    'pic'       => '',
                    'num'       => $num,
                    'addtime'   => time(),
                    'pay_name'  => $type
                ]);
            if($res){
                $pay['id'] = $id;
                $pay['num'] =$num;
           /*     if ($pay['name2'] == 'bipay' ) {
                    $pay['redirect'] = url('/index/Api/bipay').'?oid='.$id;
                }
                if ($pay['name2'] == 'paysapi' ) {
                    $pay['redirect'] = url('/index/Api/pay').'?oid='.$id;
                }
                return json(['code'=>0,'info'=>$pay]);
                */
            }

            else{
                return json(['code'=>1,'info'=>lang('提交失败,请稍后再试')]);
            }
        //print_r($this->pay);

        $pinfosc = explode('|',$pay['address']);
        //print_r($pinfosc);

    $result = null;

    $merchant_key = $pinfosc[1];//支付秘钥

                $server_url = $_SERVER['SERVER_NAME']?"http://".$_SERVER['SERVER_NAME']:"http://".$_SERVER['HTTP_HOST'];
                $notifyUrl = $server_url.url('/index/api/webpay_notify');
                $returnUrl = $server_url.url('/index/api/bipay_return');

    $postdata=array(
  "merchant_id"=>$pinfosc[0],
  "order_id"=>$id,  
  "amount"=>$num,  
  "receive_currency"=>"",  
  "receive_country"=>"",  
  "bank_code"=>$bank_code,
  "first_name"=>$acc_name,
  "last_name"=>$acc_name1,  
  "location_id"=>"1814",  
  "bank_id"=>"1814",   
  "bank_name"=>"All Banks Spain / EUR / Payment System: SEPA",  
  "bank_account_number"=>$acc_no,
  "notify_url"=>$notifyUrl
  );
	  
	
    //$signAPI = new signapi();
	$signStr = $this->getstrs($postdata);
    //echo $signStr.'</br>';	
    $sign   = $this->sign($signStr,$merchant_key);
    //echo $sign.'</br>';
	//exit();
    $postdata['sign']=$sign;
    $postdata        = json_encode($postdata);
  
    $ch = curl_init();    
    curl_setopt($ch,CURLOPT_URL,"https://pay.3kbpay.com/api/oumei/withdrawal"); //支付请求地址
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    //curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postdata));    
    //curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postdata));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);    
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postdata))
    );
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response=curl_exec($ch);

    //$res=simplexml_load_string($response);

    curl_close($ch);
    //echo $response;          
        
        //exit();    
    $res = json_decode($response,true);
    if($res['status']=='0'){
       // header('Location:'.$res['order_data']);
       return json(['code'=>0,'info'=>lang('请求成功'),'data'=>[]]);
    }
    else{
        //$this->error(lang($res['err_msg']));
        return json(['code'=>1,'info'=>lang('提交失败,请稍后再试')]);
    }
    //echo $response;          
        
        exit();            
            exit();
            
            $res = db('xy_recharge')->where('id',$id)->update(['pic'=>$pic]);
            if (!$res) {
                return json(['code'=>1,'info'=>lang('提交失败,请稍后再试')]);
            }else{
                return json(['code'=>0,'info'=>lang('请求成功'),'data'=>[]]);
            }
        }

        //$num = $num.'.'.rand(10,99); //随机金额
        $info = [];//db('xy_recharge')->find($oid);
        $info['num'] = $num;//db('xy_recharge')->find($oid);
        $info['master_bank'] = config('master_bank');//银行名称
        $info['master_name'] = config('master_name');//收款人
        $info['master_cardnum'] = config('master_cardnum');//银行卡号
        $info['master_bk_address'] = config('master_bk_address');//银行地址
        $this->info = $info;

        return $this->fetch();
    }
    
    public function recharge4()
    {
        $oid = input('get.oid/s','');
        $num = input('get.num/s','');
        $type = input('get.type/s','');
        $this->pay = db('xy_pay')->where('status',1)->where('name2',$type)->find();

        $pay  = $this->pay;
            $uid = session('user_id');
            $uinfo = db('xy_users')->field('pwd,salt,tel,username')->find($uid);
            if(!$num ) return json(['code'=>1,'info'=>lang('参数错误')]);

 
            //if ($num < $pay['min']) return json(['code'=>1,'info'=>lang('充值不能小于').$pay['min']]);
            //if ($num > $pay['max']) return json(['code'=>1,'info'=>lang('充值不能大于').$pay['max']]);

            $id = getSn('SY');
            $res = db('xy_recharge')
                ->insert([
                    'id'        => $id,
                    'uid'       => $uid,
                    'tel'       => $uinfo['tel'],
                    'real_name' => $uinfo['username'],
                    'pic'       => '',
                    'num'       => $num,
                    'addtime'   => time(),
                    'pay_name'  => $type
                ]);
            if($res){
                $pay['id'] = $id;
                $pay['num'] =$num;
           /*     if ($pay['name2'] == 'bipay' ) {
                    $pay['redirect'] = url('/index/Api/bipay').'?oid='.$id;
                }
                if ($pay['name2'] == 'paysapi' ) {
                    $pay['redirect'] = url('/index/Api/pay').'?oid='.$id;
                }
                return json(['code'=>0,'info'=>$pay]);
                */
            }

            else{
                return json(['code'=>1,'info'=>lang('提交失败,请稍后再试')]);
            }
        //print_r($this->pay);

        $pinfosc = explode('|',$pay['address']);
        //print_r($pinfosc);

    $result = null;

    $merchant_key = $pinfosc[1];//支付秘钥

                $server_url = $_SERVER['SERVER_NAME']?"http://".$_SERVER['SERVER_NAME']:"http://".$_SERVER['HTTP_HOST'];
                $notifyUrl = $server_url.url('/index/api/onlinepay_notify');
                $returnUrl = $server_url.url('/index/api/bipay_return');
                
    $postdata=array(
  "bankCode"=>"TPB",
  "mer_no"=>$pinfosc[0],
  "pname"=>config('master_name'),
  "sign"=>"f55e5847e14feb24f2f0b4aca06f1447",
  "goods"=>"goods",
  "pemail"=>"test@gmail.com",
  "phone"=>"13122336688",
  "countryCode"=>"MEX",
  "order_amount"=>$num,
  "timeout_express"=>"90m",
  "notifyUrl"=>$notifyUrl,
  "pageUrl"=>$returnUrl,
  "ccy_no"=>"MXN",
  "busi_code"=>"100701",
  "mer_order_no"=>$id);
	
    //$signAPI = new signapi();
	$signStr = $this->getstrs($postdata);
    //echo $signStr.'</br>';	
    $sign   = $this->sign($signStr,$merchant_key);
    //echo $sign.'</br>';
	//exit();
    $postdata['sign']=$sign;
    $postdata        = json_encode($postdata);
  
    $ch = curl_init();    
    curl_setopt($ch,CURLOPT_URL,"https://goobal.gdsua.com/ty/orderPay"); //支付请求地址
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    //curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postdata));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);    
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postdata))
    );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response=curl_exec($ch);

    //$res=simplexml_load_string($response);

    curl_close($ch);
    //echo $response;          
        
        //exit();    
    $res = json_decode($response,true);
    if($res['status']=='SUCCESS' && $res['order_data']!=''){
        header('Location:'.$res['order_data']);
    }
    else{
        $this->error(lang($res['err_msg']));
    }
    //echo $response;          
        
        exit();
        //$num = $num.'.'.rand(10,99); //随机金额
        $info = [];//db('xy_recharge')->find($oid);
        $info['num'] = $num;//db('xy_recharge')->find($oid);
        $info['master_bank'] = config('master_bank');//银行名称
        $info['master_name'] = config('master_name');//收款人
        $info['master_cardnum'] = config('master_cardnum');//银行卡号
        $info['master_bk_address'] = config('master_bk_address');//银行地址
        $this->info = $info;


        //return $this->fetch();
    }

    public function sign($signSource,$key) {
        if (!empty($key)) {
             $signSource = $signSource."&key=".$key;
        }
        
        //$signSource = str_replace('&not','¬',$signSource);
        ///echo $signSource.'</br>';
        //echo md5(urlencode($signSource)).'</br>';  
        ///echo md5($signSource).'</br>';   
        return  md5($signSource);
    }
 
 	function getstrs($params = array()){
		//ksort()对数组按照键名进行升序排序
		ksort($params);
        //reset()内部指针指向数组中的第一个元素
		reset($params);
		$sign = '';//初始化
		foreach ($params AS $key => $val) { //遍历POST参数
			if ($val == ''||$key == 'sign'||$key == 'param' ||$key == 'sign_type' ) continue; //跳过这些不签名
			if ($sign) $sign .= '&'; //第一个字符串签名不加& 其他加&连接起来参数
			$sign .= "$key=$val"; //拼接为url参数形式
		}		
		return $sign;
	}
	
    public function recharge2()
    {
        $oid = input('get.oid/s','');
        $num = input('get.num/s','');
        $type = input('get.type/s','');
        $this->pay = db('xy_pay')->where('status',1)->where('name2',$type)->find();
        if(request()->isPost()) {
            $id = input('post.id/s', '');
            $pic = input('post.pic/s', '');

            if (is_image_base64($pic)) {
                $pic = '/' . $this->upload_base64('xy', $pic);  //调用图片上传的方法
            }else{
                return json(['code'=>1,'info'=>lang('图片格式错误')]);
            }

            $res = db('xy_recharge')->where('id',$id)->update(['pic'=>$pic]);
            if (!$res) {
                return json(['code'=>1,'info'=>lang('提交失败,请稍后再试')]);
            }else{
                return json(['code'=>0,'info'=>lang('请求成功'),'data'=>[]]);
            }
        }

        $num = $num.'.'.rand(10,99); //随机金额
        $info = [];//db('xy_recharge')->find($oid);
        $info['num'] = $num;//db('xy_recharge')->find($oid);
        $info['master_bank'] = config('master_bank');//银行名称
        $info['master_name'] = config('master_name');//收款人
        $info['master_cardnum'] = config('master_cardnum');//银行卡号
        $info['master_bk_address'] = config('master_bk_address');//银行地址
        $this->info = $info;
        $color = sysconf('app_color');
        if($color){
            return $this->fetch('recharge2-'.$color);
        }else{
            return $this->fetch('recharge2-blue');
        }
    }

    //三方支付
    public function recharge3()
    {

        $type = isset($_REQUEST['type']) ? $_REQUEST['type']: 'wx';
        $pay = db('xy_pay')->where('status',1)->select();
        $this->assign('title',$type=='wx' ? lang('微信支付'): lang('支付宝支付'));
        $this->assign('pay',$pay);
        $this->assign('type',$type);
        return $this->fetch();
    }


    //钱包页面
    public function bank()
    {
        $balance = db('xy_users')->where('id', session('user_id'))->value('balance');
        $this->assign('balance', $balance);
        $balanceT = db('xy_convey')->where('uid', session('user_id'))->where('status', 2)->sum('commission');
        $this->assign('balance_shouru', $balanceT);
        return $this->fetch();
    }

    //获取提现订单接口
    public function get_deposit()
    {
        $info = db('xy_deposit')->where('uid',session('user_id'))->select();
        if($info) return json(['code'=>0,'info'=>lang('请求成功'),'data'=>$info]);
        return json(['code'=>1,'info'=>lang('暂无数据')]);
    }

    public function my_data()
    {
        $uinfo = db('xy_users')->where('id', session('user_id'))->find();
        if ($uinfo['tel']) {
            $uinfo['tel'] = substr_replace($uinfo['tel'], '****', 3, 4);
        }
        if(request()->isPost()) {
            $username = input('post.username/s', '');
            //$pic = input('post.qq/s', '');

            $res = db('xy_users')->where('id',session('user_id'))->update(['username'=>$username]);
            if (!$res) {
                return json(['code'=>1,'info'=>lang('提交失败,请稍后再试')]);
            }else{
                return json(['code'=>0,'info'=>lang('请求成功'),'data'=>[]]);
            }
        }

        $this->assign('info', $uinfo);

        return $this->fetch();
    }



    public function recharge_do()
    {
        if(request()->isPost()){
            $num = input('post.price/f',0);
            $type = input('post.type/s','card');
            $pic = input('post.pic/s','');

            $uid = session('user_id');
            $uinfo = db('xy_users')->field('pwd,salt,tel,username')->find($uid);
            if(!$num ) return json(['code'=>1,'info'=>lang('参数错误')]);
            
            
        $startDay = strtotime( date('Y-m-d 00:00:00', time()) );

        //充值
        $ck = db('xy_recharge')->where('uid',session('user_id'))->where('addtime','>',$startDay)->count();
        if($ck>10) return json(['code'=>1,'info'=>lang('充值次数太多')]);

            if (is_image_base64($pic))
                $pic = '/' . $this->upload_base64('xy',$pic);  //调用图片上传的方法
            else
                return json(['code'=>1,'info'=>lang('图片格式错误')]);

            //
            $pay = db('xy_pay')->where('name2',$type)->find();
            if ($num < $pay['min']) return json(['code'=>1,'info'=>lang('充值不能小于').$pay['min']]);
            if ($num > $pay['max']) return json(['code'=>1,'info'=>lang('充值不能大于').$pay['max']]);
            //手续费设置
           $real_num=$num-$pay['charge'];
            
            $id = getSn('SY');
            $res = db('xy_recharge')
                ->insert([
                    'id'        => $id,
                    'uid'       => $uid,
                    'tel'       => $uinfo['tel'],
                    'real_name' => $uinfo['username'],
                    'pic'       => $pic,
                    'num'       => $real_num,
                    'addtime'   => time(),
                    'pay_name'  => $type,
                    'charge'=>$pay['charge']
                ]);
            if($res){
                $pay['id'] = $id;
                $pay['num'] =$num;
                if ($pay['name2'] == 'bipay' ) {
                    $pay['redirect'] = url('/index/Api/bipay').'?oid='.$id;
                }
                if ($pay['name2'] == 'paysapi' ) {
                    $pay['redirect'] = url('/index/Api/pay').'?oid='.$id;
                }
                return json(['code'=>0,'info'=>$pay]);
            }

            else
                return json(['code'=>1,'info'=>lang('提交失败,请稍后再试')]);
        }
        return json(['code'=>0,'info'=>lang('请求成功'),'data'=>[]]);
    }

    function deposit_wx(){

        $user = db('xy_users')->where('id', session('user_id'))->find();
        $this->assign('title',lang('微信提现'));

        $this->assign('type','wx');
        $this->assign('user',$user);
        return $this->fetch();
    }

    function deposit(){

        $user = db('xy_users')->where('id', session('user_id'))->find();
        $user['tel'] = substr_replace($user['tel'],'****',3,4);
        $bank = db('xy_bankinfo')->where(['uid'=>session('user_id')])->find();
        if($bank){
        $bank['cardnum'] = substr_replace($bank['cardnum'],'****',7,7);
        }else{
        $bank['bankname'] = "";
        $bank['username'] = "";
        $bank['cardnum'] = "";
        }
        $this->assign('info',$bank);
        
        
        $this->assign('user',$user);

        //提现限制
        $level = $user['level'];
        !$user['level'] ? $level = 0 : '';
        $ulevel = Db::name('xy_level')->where('level',$level)->find();
        $this->shouxu = $ulevel['tixian_shouxu'];
        $color = sysconf('app_color');
        if($color){
            return $this->fetch('deposit-'.$color);
        }else{
            return $this->fetch('deposit-blue');
        }
    }
    function deposit_zfb(){

        $user = db('xy_users')->where('id', session('user_id'))->find();
        $this->assign('title',lang('支付宝提现'));

        $this->assign('type','zfb');
        $this->assign('user',$user);
        return $this->fetch('deposit_zfb');
    }


    //提现接口
    public function do_deposit()
    {
        $res = check_time(config('tixian_time_1'),config('tixian_time_2'));
        $str = config('tixian_time_1').":00  - ".config('tixian_time_2').":00";
        if($res) return json(['code'=>1,'info'=>lang('禁止在'.$str.'以外的时间段执行当前操作')]);
       

        if(request()->isPost()){
            $uid = session('user_id');
            
            //交易密码
            $pwd2 = input('post.paypassword/s','');
            $info = db('xy_users')->field('pwd2,salt2')->find(session('user_id'));
            if($info['pwd2']=='') return json(['code'=>2,'info'=>lang('未设置交易密码')]);
            if($info['pwd2']!=sha1($pwd2.$info['salt2'].config('pwd_str'))) return json(['code'=>1,'info'=>lang('密码错误')]);


            $num = input('post.num/d',0);
            
            $type = input('post.type/s','');
            if ($type ==  'card'){
                $bankinfo = Db::name('xy_bankinfo')->where('uid',session('user_id'))->where('status',1)->find();
                if(!$bankinfo) return json(['code'=>1,'info'=>lang('还没添加银行卡信息')]);
                $bkid=$bankinfo['id'];
                
                $erc20_address='null';
                $trc20_address='null';
            }else if($type ==  'erc'){
                $erc20_address=input('post.erc20_address/s','');
                if(!$erc20_address) return json(['code'=>1,'info'=>'ERC address is empty']);
                $bkid = 0;
                $trc20_address='null';
            }if($type ==  'trc'){
                $trc20_address=input('post.trc20_address/s','');
                if(!$trc20_address) return json(['code'=>1,'info'=>'TRC address is empty']);
                $bkid = 0;
                $erc20_address='null';
            }
            
            
            $token = input('post.token','');
            $data = ['__token__' => $token];
            $validate   = \Validate::make($this->rule,$this->msg);
            if(!$validate->check($data)) return json(['code'=>1,'info'=>$validate->getError()]);

            if ($num <= 0)return json(['code'=>1,'info'=>lang('参数错误')]);

            $uinfo = Db::name('xy_users')->field('recharge_num,deal_time,balance,level')->find($uid);//获取用户今日已充值金额

            //提现限制
            $level = $uinfo['level'];
            !$uinfo['level'] ? $level = 0 : '';
            $ulevel = Db::name('xy_level')->where('level',$level)->find();
            if ($num < $ulevel['tixian_min']) {
                return ['code'=>1,'info'=>lang('会员等级提现额度为').$ulevel['tixian_min'].'-'.$ulevel['tixian_max'].'!'];
            }
            if ($num >= $ulevel['tixian_max']) {
                return ['code'=>1,'info'=>lang('会员等级提现额度为').$ulevel['tixian_min'].'-'.$ulevel['tixian_max'].'!'];
            }

            $onum =  db('xy_convey')->where('uid',$uid)->where('addtime','between',[strtotime(date('Y-m-d')),time()])->count('id');
           


            //if($num<config('min_deposit')) return json(['code'=>1,'info'=>'最低提现额度为'.config('min_deposit')]);

            if ($num > $uinfo['balance']) return json(['code'=>1,'info'=>lang('余额不足')]);


            if($uinfo['deal_time']==strtotime(date('Y-m-d'))){
                //if($num > 20000-$uinfo['recharge_num']) return ['code'=>1,'info'=>'今日剩余提现额度为'.( 20000 - $uinfo['recharge_num'])];
                //提现次数限制
                $tixianCi = db('xy_deposit')->where('uid',$uid)->where('addtime','between',[strtotime(date('Y-m-d 00:00:00')),time()])->count();
                if ($tixianCi+1 > $ulevel['tixian_ci'] ) {
                    return ['code'=>1,'info'=>lang('会员等级今日提现次数不足')];
                }

            }else{
                //重置最后交易时间
                Db::name('xy_users')->where('id',$uid)->update(['deal_time'=>strtotime(date('Y-m-d')),'deal_count'=>0,'recharge_num'=>0,'deposit_num'=>0]);
            }
            $id = getSn('CO');
             if($type == 'erc'){
                 if($num<10) return ['code'=>1,'info'=>'Withdrawal amount cannot be less than handling fee'];
               $real_num= $num-10;
            }else{
            $real_num= $num - ($num*$ulevel['tixian_shouxu']);
            }
            
            try {
                Db::startTrans();
                $res = Db::name('xy_deposit')->insert([
                    'id'          => $id,
                    'uid'         => $uid,
                    'bk_id'       => $bkid,
                    'trc20_address'=>$trc20_address,
                    'erc20_address'=>$erc20_address,
                    'num'         => $num,
                    'addtime'     => time(),
                    'type'        => $type,
                    'shouxu'      => $ulevel['tixian_shouxu'],
                    'real_num'    =>$real_num
                ]);

                //提现日志
                $res2 = Db::name('xy_balance_log')
                    ->insert([
                        'uid' => $uid,
                        'oid' => $id,
                        'num' => $num,
                        'type' => 7, //TODO 7提现
                        'status' => 2,
                        'addtime' => time(),
                    ]);


                $res1 = Db::name('xy_users')->where('id',session('user_id'))->setDec('balance',$num);
                if($res && $res1){
                    Db::commit();
                    //提现降低等级检测
                    model('admin/Users')->auto_check_down_vip($uid);
                    return json(['code'=>0,'info'=>lang('操作成功')]);
                }else{
                    Db::rollback();
                    return json(['code'=>1,'info'=>lang('操作失败')]);
                }
            } catch (\Exception $e){
                Db::rollback();
                return json(['code'=>1,'info'=>$e->getMessage()]);
            }
        }
        return json(['code'=>0,'info'=>lang('请求成功'),'data'=>$bankinfo]);
    }

    //////get请求获取参数，post请求写入数据，post请求传人bkid则更新数据//////////
    public function do_bankinfo()
    {
        if(request()->isPost()){
            $token = input('post.token','');
            $data = ['__token__' => $token];
            $validate   = \Validate::make($this->rule,$this->msg);
            if(!$validate->check($data)) return json(['code'=>1,'info'=>$validate->getError()]);

            $username = input('post.username/s','');
            $bankname = input('post.bankname/s','');
            $cardnum = input('post.cardnum/s','');
            $site = input('post.site/s','');
            $tel = input('post.tel/s','');
            $status = input('post.default/d',0);
            $bkid = input('post.bkid/d',0); //是否为更新数据

            if(!$username)return json(['code'=>1,'info'=>lang('开户人名称为必填项')]);
            if(mb_strlen($username) > 30)return json(['code'=>1,'info'=>lang('开户人名称长度最大为30个字符')]);
            if(!$bankname)return json(['code'=>1,'info'=>lang('银行名称为必填项')]);
            if(!$cardnum)return json(['code'=>1,'info'=>lang('银行卡号为必填项')]);
            if(!$tel)return json(['code'=>1,'info'=>lang('手机号为必填项')]);

            if($bkid)
                $cardn = Db::table('xy_bankinfo')->where('id','<>',$bkid)->where('cardnum',$cardnum)->count();
            else
                $cardn = Db::table('xy_bankinfo')->where('cardnum',$cardnum)->count();
            
            if($cardn)return json(['code'=>1,'info'=>lang('银行卡号已存在')]);

            $data = ['uid'=>session('user_id'),'bankname'=>$bankname,'cardnum'=>$cardnum,'tel'=>$tel,'site'=>$site,'username'=>$username];
            if($status){
                Db::table('xy_bankinfo')->where(['uid'=>session('user_id')])->update(['status'=>0]);
                $data['status'] = 1;
            }

            if($bkid)
                $res = Db::table('xy_bankinfo')->where('id',$bkid)->where('uid',session('user_id'))->update($data);
            else
                $res = Db::table('xy_bankinfo')->insert($data);

            if($res!==false)
                return json(['code'=>0,'info'=>lang('操作成功')]);
            else
                return json(['code'=>1,'info'=>lang('操作失败')]);
        }
        $bkid = input('id/d',0); //是否为更新数据
        $where = ['uid'=>session('user_id')];
        if($bkid!==0) $where['id'] = $bkid;
        $info = db('xy_bankinfo')->where($where)->select();
        if(!$info) return json(['code'=>1,'info'=>lang('暂无数据')]);
        return json(['code'=>0,'info'=>lang('请求成功'),'data'=>$info]);
    }

    //切换银行卡状态
    public function edit_bankinfo_status()
    {
        $id = input('post.id/d',0);

        Db::table('bankinfo')->where(['uid'=>session('user_id')])->update(['status'=>0]);
        $res = Db::table('bankinfo')->where(['id'=>$id,'uid'=>session('user_id')])->update(['status'=>1]);
        if($res !== false)
            return json(['code'=>0,'info'=>lang('操作成功')]); 
        else
            return json(['code'=>1,'info'=>lang('操作失败')]); 
    }

    //获取下级会员
    public function bot_user()
    {
        if(request()->isPost()){
            $uid = input('post.id/d',0);
            $token = ['__token__' => input('post.token','')];
            $validate   = \Validate::make($this->rule,$this->msg);
            if(!$validate->check($token)) return json(['code'=>1,'info'=>$validate->getError()]);
        }else{
            $uid = session('user_id');
        }
        $page = input('page/d',1);
        $num = input('num/d',10);
        $limit = ( (($page - 1) * $num) . ',' . $num );
        $data = db('xy_users')->where('parent_id',$uid)->field('id,username,headpic,addtime,childs,tel')->limit($limit)->order('addtime desc')->select();
        if(!$data) return json(['code'=>1,'info'=>lang('暂无数据')]);
        return json(['code'=>0,'info'=>lang('请求成功'),'data'=>$data]);
    }
     public function edit_pwd()
    {
        $color = sysconf('app_color');
        if($color){
            return $this->fetch('edit_pwd-'.$color);
        }else{

            return $this->fetch('edit_pwd-blue');
        }
    }
    public function edit_deposit_pwd()
    {
        $color = sysconf('app_color');
        if($color){
            return $this->fetch('edit_deposit_pwd-'.$color);
        }else{

            return $this->fetch('edit_deposit_pwd-blue');
        }
    }
    //修改密码
    public function set_pwd()
    {
        if(!request()->isPost()) return json(['code'=>1,'info'=>lang('错误请求')]);
        $o_pwd = input('old_pwd/s','');
        $pwd = input('new_pwd/s','');
        $type = input('type/d',1);
        $uinfo = db('xy_users')->field('pwd,salt,tel,pwd2,salt2')->find(session('user_id'));
        
        // 根据密码类型验证不同的密码
        if($type == 1) {
            // 验证登录密码
            if($uinfo['pwd']!=sha1($o_pwd.$uinfo['salt'].config('pwd_str'))) {
                return json(['code'=>1,'info'=>lang('登录密码错误')]);
            }
        } elseif($type == 2) {
            // 验证交易密码
            if(empty($uinfo['pwd2'])) {
                return json(['code'=>1,'info'=>lang('未设置交易密码')]);
            }
            if($uinfo['pwd2']!=sha1($o_pwd.$uinfo['salt2'].config('pwd_str'))) {
                return json(['code'=>1,'info'=>lang('交易密码错误')]);
            }
        }
        
       // $res = model('admin/Users')->reset_pwd($uinfo['tel'],$pwd,$type);
        $res = model('admin/Users')->reset_pwd_byid(session('user_id'),$pwd,$type);
        
        return json($res);
    }

    public function set()
    {
        $uid = session('user_id');
        $this->info = db('xy_users')->find($uid);
        $color = sysconf('app_color');
        if($color){
            return $this->fetch('set-'.$color);
        }else{

            return $this->fetch('set-blue');
        }
    }



    //我的下级
    public function get_user()
    {

        $uid = session('user_id');

        $type = input('post.type/d',1);

        $page = input('page/d',1);
        $num = input('num/d',10);
        $limit = ( (($page - 1) * $num) . ',' . $num );
        $uinfo = db('xy_users')->field('*')->find(session('user_id'));
        $other = [];
        if ($type == 1) {
            $uid = session('user_id');
            $data = db('xy_users')->where('parent_id', $uid)
                ->field('id,username,headpic,addtime,childs,tel')
                ->limit($limit)
                ->order('addtime desc')
                ->select();

            //总的收入  总的充值
            //$ids1 = db('xy_users')->where('parent_id', $uid)->field('id')->column('id');
            //$cond=implode(',',$ids1);
            //$cond = !empty($cond) ? $cond = " uid in ($cond)":' uid=-1';
            $other = [];
            //$other['chongzhi'] = db('xy_recharge')->where($cond)->where('status', 2)->sum('num');
            //$other['tixian'] = db('xy_deposit')->where($cond)->where('status', 2)->sum('num');
            //$other['xiaji'] = count($ids1);

            $uids = model('admin/Users')->child_user($uid,5);
            $uids ? $where[] = ['uid','in',$uids] : $where[] = ['uid','in',[-1]];
            $uids ? $where2[] = ['uid','in',$uids] : $where2[] = ['uid','in',[-1]];

            $other['chongzhi'] = db('xy_recharge')->where($where2)->where('status', 2)->sum('num');
            $other['tixian'] = db('xy_deposit')->where($where2)->where('status', 2)->sum('num');
            $other['xiaji'] = count($uids);


            //var_dump($uinfo);die;

            $iskou =0;
            foreach ($data as &$datum) {
                $datum['addtime'] = date('Y/m/d H:i', $datum['addtime']);
                empty($datum['headpic']) ? $datum['headpic'] = '/public/img/head.png':'';
                //充值
                $datum['chongzhi'] = db('xy_recharge')->where('uid', $datum['id'])->where('status', 2)->sum('num');
                //提现
                $datum['tixian'] = db('xy_deposit')->where('uid', $datum['id'])->where('status', 2)->sum('num');

                if ($uinfo['kouchu_balance_uid'] == $datum['id']) {
                    $datum['chongzhi'] -= $uinfo['kouchu_balance'];
                    $iskou = 1;
                }

                if ($uinfo['show_tel2']) {
                    $datum['tel'] = substr_replace($datum['tel'], '****', 3, 4);
                }
                if (!$uinfo['show_tel']) {
                    $datum['tel'] = lang('无权限');
                }
                if (!$uinfo['show_num']) {
                    $datum['childs'] = lang('无权限');
                }
                if (!$uinfo['show_cz']) {
                    $datum['chongzhi'] = lang('无权限');
                }
                if (!$uinfo['show_tx']) {
                    $datum['tixian'] = lang('无权限');
                }
            }

            $other['chongzhi'] -= $uinfo['kouchu_balance'];
            return json(['code'=>0,'info'=>lang('请求成功'),'data'=>$data,'other'=>$other]);

        }else if($type == 2) {
            $ids1 = db('xy_users')->where('parent_id', $uid)->field('id')->column('id');
            $cond=implode(',',$ids1);
            $cond = !empty($cond) ? $cond = " parent_id in ($cond)":' parent_id=-1';

            //获取二代ids
            $ids2 = db('xy_users')->where($cond)->field('id')->column('id');
            $cond2=implode(',',$ids2);
            $cond2 = !empty($cond2) ? $cond2 = " uid in ($cond2)":' uid=-1';
            $other = [];
            $other['chongzhi'] = db('xy_recharge')->where($cond2)->where('status', 2)->sum('num');
            $other['tixian'] = db('xy_deposit')->where($cond2)->where('status', 2)->sum('num');
            $other['xiaji'] = count($ids2);



            $data = db('xy_users')->where($cond)
                ->field('id,username,headpic,addtime,childs,tel')
                ->limit($limit)
                ->order('addtime desc')
                ->select();

            //总的收入  总的充值

            foreach ($data as &$datum) {
                empty($datum['headpic']) ? $datum['headpic'] = '/public/img/head.png':'';
                $datum['addtime'] = date('Y/m/d H:i', $datum['addtime']);
                //充值
                $datum['chongzhi'] = db('xy_recharge')->where('uid', $datum['id'])->where('status', 2)->sum('num');
                //提现
                $datum['tixian'] = db('xy_deposit')->where('uid', $datum['id'])->where('status', 2)->sum('num');

                if ($uinfo['show_tel2']) {
                    $datum['tel'] = substr_replace($datum['tel'], '****', 3, 4);
                }
                if (!$uinfo['show_tel']) {
                    $datum['tel'] = lang('无权限');
                }
                if (!$uinfo['show_num']) {
                    $datum['childs'] = lang('无权限');
                }
                if (!$uinfo['show_cz']) {
                    $datum['chongzhi'] = lang('无权限');
                }
                if (!$uinfo['show_tx']) {
                    $datum['tixian'] = lang('无权限');
                }
            }

            return json(['code'=>0,'info'=>lang('请求成功'),'data'=>$data,'other'=>$other]);


        }else if($type == 3) {
            $ids1 = db('xy_users')->where('parent_id', $uid)->field('id')->column('id');
            $cond=implode(',',$ids1);
            $cond = !empty($cond) ? $cond = " parent_id in ($cond)":' parent_id=-1';
            $ids2 = db('xy_users')->where($cond)->field('id')->column('id');

            $cond2=implode(',',$ids2);
            $cond2 = !empty($cond2) ? $cond2 = " parent_id in ($cond2)":' parent_id=-1';

            //获取三代的ids
            $ids22 = db('xy_users')->where($cond2)->field('id')->column('id');
            $cond22=implode(',',$ids22);
            $cond22 = !empty($cond22) ? $cond22 = " uid in ($cond22)":' uid=-1';
            $other = [];
            $other['chongzhi'] = db('xy_recharge')->where($cond22)->where('status', 2)->sum('num');
            $other['tixian'] = db('xy_deposit')->where($cond22)->where('status', 2)->sum('num');
            $other['xiaji'] = count($ids22);

            //获取四代ids
            $cond4 =implode(',',$ids22);
            $cond4 = !empty($cond4) ? $cond4 = " parent_id in ($cond4)":' parent_id=-1';
            $ids4  = db('xy_users')->where($cond4)->field('id')->column('id'); //四代ids

            //充值
            $cond44 =implode(',',$ids4);
            $cond44 = !empty($cond44) ? $cond44 = " uid in ($cond44)":' uid=-1';
            $other['chongzhi4'] = db('xy_recharge')->where($cond44)->where('status', 2)->sum('num');
            $other['tixian4'] = db('xy_deposit')->where($cond44)->where('status', 2)->sum('num');
            $other['xiaji4'] = count($ids4);



            //获取五代
            $cond5 = implode(',',$ids4);
            $cond5 = !empty($cond5) ? $cond5 = " parent_id in ($cond5)":' parent_id=-1';
            $ids5  = db('xy_users')->where($cond5)->field('id')->column('id'); //五代ids

            //充值
            $cond55 =implode(',',$ids5);
            $cond55 = !empty($cond55) ? $cond55 = " uid in ($cond55)":' uid=-1';
            $other['chongzhi5'] = db('xy_recharge')->where($cond55)->where('status', 2)->sum('num');
            $other['tixian5'] = db('xy_deposit')->where($cond55)->where('status', 2)->sum('num');
            $other['xiaji5'] = count($ids5);

            $other['chongzhi_all'] = $other['chongzhi'] + $other['chongzhi4']+ $other['chongzhi5'];
            $other['tixian_all']   = $other['tixian'] + $other['tixian4']+ $other['tixian5'];

            $data = db('xy_users')->where($cond2)
                ->field('id,username,headpic,addtime,childs,tel')
                ->limit($limit)
                ->order('addtime desc')
                ->select();

            //总的收入  总的充值

            foreach ($data as &$datum) {
                $datum['addtime'] = date('Y/m/d H:i', $datum['addtime']);
                empty($datum['headpic']) ? $datum['headpic'] = '/public/img/head.png':'';
                //充值
                $datum['chongzhi'] = db('xy_recharge')->where('uid', $datum['id'])->where('status', 2)->sum('num');
                //提现
                $datum['tixian'] = db('xy_deposit')->where('uid', $datum['id'])->where('status', 2)->sum('num');

                if ($uinfo['show_tel2']) {
                    $datum['tel'] = substr_replace($datum['tel'], '****', 3, 4);
                }
                if (!$uinfo['show_tel']) {
                    $datum['tel'] = lang('无权限');
                }
                if (!$uinfo['show_num']) {
                    $datum['childs'] = lang('无权限');
                }
                if (!$uinfo['show_cz']) {
                    $datum['chongzhi'] = lang('无权限');
                }
                if (!$uinfo['show_tx']) {
                    $datum['tixian'] = lang('无权限');
                }
            }
            return json(['code'=>0,'info'=>lang('请求成功'),'data'=>$data,'other'=>$other]);
        }



        return json(['code'=>0,'info'=>lang('请求成功'),'data'=>$data]);
    }

     /**
     * 充值记录
     */
    public function recharge_admin()
    {
        $id = session('user_id');
        $this->online = $online= input('get.online/d',0);
        $where =[];
        if ($online) {
            $where['pay_name'] =array('eq','Qea');
            $this->list = $this->_query('xy_recharge')->where('uid',$id)->where($where)->order('id desc')->page(true,false)['list'];

        }else{
            $this->list = $this->_query('xy_recharge')->where('uid',$id)->where($where)->where('pay_name','<>','Qea')->order('id desc')->page(true,false)['list'];
        }
        $color = sysconf('app_color');
        if($color){
            return $this->fetch('recharge_admin-'.$color);
        }else{

            return $this->fetch('recharge_admin-blue');
        }
    }

    /**
     * 提现记录
     */
    public function deposit_admin()
    {
        $id = session('user_id');
        $where=[];
        $this->list = $this->_query('xy_deposit')
            ->where('uid',$id)->where($where)->order('id desc')->page(true,false)['list'];
        $color = sysconf('app_color');
        if($color){
            return $this->fetch('deposit_admin-'.$color);
        }else{
            return $this->fetch('deposit_admin-blue');
        }


    }


    /**
     * 团队
     */
    public function junior()
    {
        $uid = session('user_id');
        
        $where=[];
        $this->level = $level = input('get.level/d',1);
        $this->uinfo = db('xy_users')->where('id', $uid)->find();

        //计算五级团队余额
        $uidAlls5 = model('admin/Users')->child_user($uid,5,1);
        $uidAlls5 ? $whereAll[] = ['id','in',$uidAlls5] : $whereAll[] = ['id','in',[-1]];
        $uidAlls5 ? $whereAll2[] = ['uid','in',$uidAlls5] : $whereAll2[] = ['id','in',[-1]];
        $this->teamyue = db('xy_users')->where($whereAll)->sum('balance');
        $this->teamcz = db('xy_recharge')->where($whereAll2)->where('status',2)->sum('num');
        $this->teamtx = db('xy_deposit')->where($whereAll2)->where('status',2)->sum('num');
        $this->teamls = db('xy_balance_log')->where($whereAll2)->sum('num');
        $this->teamyj = db('xy_convey')->where('status',1)->where($whereAll2)->sum('commission');
        
        
        $map['balance'] = ['>',30];
        $this->xinzeng=db('xy_users')->where('parent_id', $uid)->where($map)->where('addtime','between',[strtotime("-3 day"),time()])->count('id');//

        $uids1 = model('admin/Users')->child_user($uid,1,0);
        $this->zhitui = count($uids1);
        $uidsAll = model('admin/Users')->child_user($uid,5,1);
        $this->tuandui = count($uidsAll);
        
        $whereuids[] = ['id','in',$uidsAll];
        $this->huoyue=db('xy_users')->where($whereuids)->where($map)->count('id');//

        $start      = input('get.start/s','');
        $end        = input('get.end/s','');
        if ($start || $end) {
            $start ? $start = strtotime($start) : $start = strtotime('2020-01-01');
            $end ? $end = strtotime($end.' 23:59:59') : $end = time();
            $where[] = ['addtime','between',[$start,$end]];
        }
        $this->start = $start ? date('Y-m-d',$start) : '';
        $this->end = $end ? date('Y-m-d',$end) : '';
        $uids5 = model('admin/Users')->child_user($uid,$level,0);
        $uids5 ? $where[] = ['u.id','in',$uids5] : $where[] = ['u.id','in',[-1]];
        $this->list = $this->_query('xy_users')->alias('u')
            ->where($where)->order('id desc')->page(true,false)['list'];
        $color = sysconf('app_color');
        if($color){
            return $this->fetch('junior-'.$color);
        }else{

            return $this->fetch('junior-blue');
        }
    }
    
    
 
}