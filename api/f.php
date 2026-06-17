<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}
require_once __DIR__.'/db.php';
$a=$_GET['action']??'';
$m=$_SERVER['REQUEST_METHOD'];
$b=[];
if($m==='POST'){$r=file_get_contents('php://input');if($r)$b=json_decode($r,true)??[];$b=array_merge($b,$_POST);}
$tok=$_SERVER['HTTP_X_AUTH_TOKEN']??$_GET['token']??'';
$pdo=getDB();
// Setup tables
$t1="CREATE TABLE IF NOT EXISTS suppliers(id INT AUTO_INCREMENT PRIMARY KEY,company VARCHAR(10) DEFAULT 'BGPT',name VARCHAR(150) NOT NULL,contact_person VARCHAR(100),phone VARCHAR(20),email VARCHAR(150),address TEXT,gst_no VARCHAR(20),device_types TEXT,notes TEXT,is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$t2="CREATE TABLE IF NOT EXISTS purchase_orders(id INT AUTO_INCREMENT PRIMARY KEY,company VARCHAR(10) DEFAULT 'BGPT',po_number VARCHAR(30) UNIQUE NOT NULL,supplier_id INT,order_date DATE,expected_date DATE NULL,status VARCHAR(30) DEFAULT 'Draft',total_amount DECIMAL(10,2) DEFAULT 0,paid_amount DECIMAL(10,2) DEFAULT 0,payment_mode VARCHAR(50),payment_ref VARCHAR(100),notes TEXT,created_by VARCHAR(100),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$t3="CREATE TABLE IF NOT EXISTS purchase_order_items(id INT AUTO_INCREMENT PRIMARY KEY,po_id INT,device_model VARCHAR(100),quantity INT DEFAULT 1,received_qty INT DEFAULT 0,unit_cost DECIMAL(10,2),total_cost DECIMAL(10,2),notes TEXT)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$t4="CREATE TABLE IF NOT EXISTS expenses(id INT AUTO_INCREMENT PRIMARY KEY,company VARCHAR(10) DEFAULT 'BGPT',date DATE,category VARCHAR(50),description TEXT,amount DECIMAL(10,2),payment_mode VARCHAR(50),paid_to VARCHAR(100),reference VARCHAR(100),receipt_note TEXT,created_by VARCHAR(100),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$t5="CREATE TABLE IF NOT EXISTS finance_settings(id INT AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(50) UNIQUE,setting_value TEXT)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
foreach([$t1,$t2,$t3,$t4,$t5]as $q){try{$pdo->exec($q);}catch(Exception $e){}}
try{$pdo->exec("INSERT IGNORE INTO finance_settings(setting_key,setting_value)VALUES('finance_pin','9999')");}catch(Exception $e){}
// Auth
$cu=null;
if($a!=='verify_pin'){
    if($tok){$s=$pdo->prepare("SELECT * FROM users WHERE auth_token=? AND is_active=1");$s->execute([$tok]);$cu=$s->fetch()?:null;}
    if(!$cu){http_response_code(401);echo json_encode(['error'=>'Not authenticated']);exit;}
    if($cu['role']!=='admin'){http_response_code(403);echo json_encode(['error'=>'Admin only']);exit;}
}
if($a==='verify_pin'){
    $pin=trim($b['pin']??'');
    $s=$pdo->prepare("SELECT setting_value FROM finance_settings WHERE setting_key='finance_pin'");
    $s->execute();$stored=$s->fetchColumn()?:'9999';
    echo json_encode($pin===$stored?['success'=>true]:['success'=>false,'error'=>'Wrong PIN']);
}elseif($a==='update_pin'){
    $pin=trim($b['pin']??'');
    $pdo->prepare("INSERT INTO finance_settings(setting_key,setting_value)VALUES('finance_pin',?)ON DUPLICATE KEY UPDATE setting_value=?")->execute([$pin,$pin]);
    echo json_encode(['success'=>true]);
}elseif($a==='get_suppliers'){
    $s=$pdo->prepare("SELECT * FROM suppliers WHERE company=? AND is_active=1 ORDER BY name");
    $s->execute([$_GET['company']??'BGPT']);echo json_encode(['suppliers'=>$s->fetchAll()]);
}elseif($a==='save_supplier'){
    $id=intval($b['id']??0);
    if($id){
        $pdo->prepare("UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,gst_no=?,device_types=?,notes=?,company=? WHERE id=?")
        ->execute([trim($b['name']),trim($b['contact_person']??''),trim($b['phone']??''),trim($b['email']??''),trim($b['address']??''),trim($b['gst_no']??''),trim($b['device_types']??''),trim($b['notes']??''),$b['company']??'BGPT',$id]);
    }else{
        $pdo->prepare("INSERT INTO suppliers(company,name,contact_person,phone,email,address,gst_no,device_types,notes)VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([$b['company']??'BGPT',trim($b['name']),trim($b['contact_person']??''),trim($b['phone']??''),trim($b['email']??''),trim($b['address']??''),trim($b['gst_no']??''),trim($b['device_types']??''),trim($b['notes']??'')]);
        $id=$pdo->lastInsertId();
    }
    echo json_encode(['success'=>true,'id'=>$id]);
}elseif($a==='delete_supplier'){
    $pdo->prepare("UPDATE suppliers SET is_active=0 WHERE id=?")->execute([intval($b['id']??0)]);
    echo json_encode(['success'=>true]);
}elseif($a==='get_purchase_orders'){
    $co=$_GET['company']??'BGPT';
    $s=$pdo->prepare("SELECT p.*,s.name as supplier_name FROM purchase_orders p LEFT JOIN suppliers s ON p.supplier_id=s.id WHERE p.company=? ORDER BY p.order_date DESC");
    $s->execute([$co]);$orders=$s->fetchAll();
    foreach($orders as &$o){$i=$pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id=?");$i->execute([$o['id']]);$o['items']=$i->fetchAll();}
    echo json_encode(['orders'=>$orders]);
}elseif($a==='save_purchase_order'){
    $id=intval($b['id']??0);$items=$b['items']??[];
    $total=array_sum(array_column($items,'total_cost'));$co=$b['company']??'BGPT';
    if($id){
        $pdo->prepare("UPDATE purchase_orders SET supplier_id=?,order_date=?,expected_date=?,status=?,total_amount=?,paid_amount=?,payment_mode=?,payment_ref=?,notes=? WHERE id=?")
        ->execute([intval($b['supplier_id']),$b['order_date'],$b['expected_date']?:null,$b['status']??'Draft',$total,floatval($b['paid_amount']??0),$b['payment_mode']??'',$b['payment_ref']??'',$b['notes']??'',$id]);
        $pdo->prepare("DELETE FROM purchase_order_items WHERE po_id=?")->execute([$id]);
    }else{
        $yr=date('Y');
        $cnt=$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE YEAR(created_at)=$yr")->fetchColumn();
        $pn='PO-'.$yr.'-'.str_pad($cnt+1,4,'0',STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO purchase_orders(company,po_number,supplier_id,order_date,expected_date,status,total_amount,paid_amount,payment_mode,payment_ref,notes,created_by)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$co,$pn,intval($b['supplier_id']),$b['order_date'],$b['expected_date']?:null,$b['status']??'Draft',$total,floatval($b['paid_amount']??0),$b['payment_mode']??'',$b['payment_ref']??'',$b['notes']??'',$cu['name']]);
        $id=$pdo->lastInsertId();
    }
    foreach($items as $item){
        $pdo->prepare("INSERT INTO purchase_order_items(po_id,device_model,quantity,received_qty,unit_cost,total_cost,notes)VALUES(?,?,?,?,?,?,?)")
        ->execute([$id,$item['device_model'],intval($item['quantity']),intval($item['received_qty']??0),floatval($item['unit_cost']),floatval($item['total_cost']),$item['notes']??'']);
    }
    echo json_encode(['success'=>true,'id'=>$id]);
}elseif($a==='delete_purchase_order'){
    $id=intval($b['id']??0);
    $pdo->prepare("DELETE FROM purchase_order_items WHERE po_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM purchase_orders WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]);
}elseif($a==='get_expenses'){
    $co=$_GET['company']??'BGPT';$w=['company=?'];$pa=[$co];
    if(!empty($_GET['from'])){$w[]='date>=?';$pa[]=$_GET['from'];}
    if(!empty($_GET['to'])){$w[]='date<=?';$pa[]=$_GET['to'];}
    if(!empty($_GET['category'])){$w[]='category=?';$pa[]=$_GET['category'];}
    $s=$pdo->prepare("SELECT * FROM expenses WHERE ".implode(' AND ',$w)." ORDER BY date DESC");
    $s->execute($pa);$rows=$s->fetchAll();
    echo json_encode(['expenses'=>$rows,'total'=>array_sum(array_column($rows,'amount'))]);
}elseif($a==='save_expense'){
    $id=intval($b['id']??0);
    if($id){
        $sets=[];$vals=[];
        foreach(['company','date','category','description','amount','payment_mode','paid_to','reference','receipt_note']as $f){if(isset($b[$f])){$sets[]="$f=?";$vals[]=$b[$f];}}
        $vals[]=$id;$pdo->prepare("UPDATE expenses SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
    }else{
        $pdo->prepare("INSERT INTO expenses(company,date,category,description,amount,payment_mode,paid_to,reference,receipt_note,created_by)VALUES(?,?,?,?,?,?,?,?,?,?)")
        ->execute([$b['company']??'BGPT',$b['date'],$b['category'],$b['description'],floatval($b['amount']),$b['payment_mode']??'',$b['paid_to']??'',$b['reference']??'',$b['receipt_note']??'',$cu['name']]);
        $id=$pdo->lastInsertId();
    }
    echo json_encode(['success'=>true,'id'=>$id]);
}elseif($a==='delete_expense'){
    $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([intval($b['id']??0)]);
    echo json_encode(['success'=>true]);
}elseif($a==='get_accounts_summary'){
    $co=$_GET['company']??'BGPT';$from=$_GET['from']??date('Y-01-01');$to=$_GET['to']??date('Y-m-d');
    try{
        $q1=$pdo->prepare("SELECT COALESCE(SUM(total_price),0)ts,COALESCE(SUM(payment_received),0)tr,COALESCE(SUM(pending_payment),0)tp,COALESCE(SUM(CASE WHEN type='sales' THEN total_price ELSE 0 END),0)si,COALESCE(SUM(CASE WHEN type='license' THEN total_price ELSE 0 END),0)li,COALESCE(SUM(CASE WHEN type='sales' THEN qty ELSE 0 END),0)ds,COUNT(*)tc FROM balance_sheet_entries WHERE profile=? AND date BETWEEN ? AND ?");
        $q1->execute([$co,$from,$to]);$inc=$q1->fetch();
    }catch(Exception $e){$inc=['ts'=>0,'tr'=>0,'tp'=>0,'si'=>0,'li'=>0,'ds'=>0,'tc'=>0];}
    $q2=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0)tp,COALESCE(SUM(paid_amount),0)pp,COUNT(*)pc FROM purchase_orders WHERE company=? AND order_date BETWEEN ? AND ? AND status!='Cancelled'");
    $q2->execute([$co,$from,$to]);$pur=$q2->fetch();
    $q3=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE company=? AND date BETWEEN ? AND ?");
    $q3->execute([$co,$from,$to]);$tex=floatval($q3->fetchColumn());
    $q4=$pdo->prepare("SELECT category,COALESCE(SUM(amount),0)ct FROM expenses WHERE company=? AND date BETWEEN ? AND ? GROUP BY category ORDER BY ct DESC");
    $q4->execute([$co,$from,$to]);$cats=$q4->fetchAll();
    $q5=$pdo->prepare("SELECT DATE_FORMAT(date,'%Y-%m')month,COALESCE(SUM(total_price),0)income,COALESCE(SUM(payment_received),0)received FROM balance_sheet_entries WHERE profile=? AND date BETWEEN ? AND ? GROUP BY DATE_FORMAT(date,'%Y-%m') ORDER BY month");
    $q5->execute([$co,$from,$to]);$monthly=$q5->fetchAll();
    $gp=floatval($inc['ts'])-floatval($pur['tp']);
    echo json_encode(['income'=>['total_sales'=>$inc['ts'],'total_received'=>$inc['tr'],'total_pending'=>$inc['tp'],'sales_income'=>$inc['si'],'license_income'=>$inc['li'],'devices_sold'=>$inc['ds'],'total_entries'=>$inc['tc']],'purchases'=>['total_po'=>$pur['tp'],'po_count'=>$pur['pc']],'expenses_by_category'=>$cats,'total_expenses'=>$tex,'gross_profit'=>$gp,'net_profit'=>$gp-$tex,'monthly'=>$monthly]);
}else{
    http_response_code(404);echo json_encode(['error'=>'Unknown']);
}
