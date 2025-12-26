<?php
// ===== CONFIG =====
$BOT_TOKEN = "8412616164:AAH9QbLDeW-xW_eqVsluqRrrseOTXZqFxoI";
$ADMIN_ID  = "5492566729";
$UPI_ID    = "q411955534@ybl";
$PRODUCT   = "SHEIN APP COUPON (4000 pe 4000)";

// Coupon pool (add more lines as you get new coupons)
$COUPONS = [
  "SVF4WOBEX6YI5Q3",
  "SVFYCBFNQS26D79"
];

// Pricing slabs
function pricePerUnit($qty){
  if($qty >= 20) return 100;
  if($qty >= 10) return 120;
  if($qty >= 5)  return 140;
  return 150; // 1–4
}

// ===== TELEGRAM CORE =====
$api = "https://api.telegram.org/bot$BOT_TOKEN/";
$update = json_decode(file_get_contents("php://input"), true);

$chat_id = $update["message"]["chat"]["id"] ?? null;
$text    = trim($update["message"]["text"] ?? "");
$from_id = $update["message"]["from"]["id"] ?? null;

function tg($method, $data){
  global $api;
  $opts = ["http"=>[
    "header"=>"Content-Type: application/json",
    "method"=>"POST",
    "content"=>json_encode($data)
  ]];
  file_get_contents($api.$method, false, stream_context_create($opts));
}

// ===== SIMPLE STORAGE (JSON) =====
$storeFile = __DIR__."/store.json";
if(!file_exists($storeFile)) file_put_contents($storeFile, json_encode(["orders"=>[],"used"=>[]]));
$store = json_decode(file_get_contents($storeFile), true);

function saveStore($s){
  global $storeFile;
  file_put_contents($storeFile, json_encode($s, JSON_PRETTY_PRINT));
}

function nextCoupon(){
  global $COUPONS, $store;
  foreach($COUPONS as $c){
    if(!in_array($c, $store["used"])) return $c;
  }
  return null;
}

// ===== COMMANDS =====
if(!$chat_id) exit;

if($text=="/start"){
  $msg = "🛍 *$PRODUCT*\n\n".
         "Pricing:\n".
         "• 1–4  = ₹150\n".
         "• 5–9  = ₹140\n".
         "• 10–19 = ₹120\n".
         "• 20–100 = ₹100\n\n".
         "Send quantity (1–100) to continue.";
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg, "parse_mode"=>"Markdown"]);
  exit;
}

// If user sends a number → create order
if(ctype_digit($text)){
  $qty = intval($text);
  if($qty<1 || $qty>100){
    tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Quantity 1–100 ke beech bhejo."]);
    exit;
  }
  $ppu = pricePerUnit($qty);
  $total = $ppu * $qty;
  $orderId = time().$chat_id;

  $store["orders"][$orderId] = [
    "user"=>$chat_id, "qty"=>$qty, "total"=>$total, "utr"=>null, "approved"=>false
  ];
  saveStore($store);

  // UPI QR (simple dynamic QR via api.qrserver.com)
  $upiLink = "upi://pay?pa=".$GLOBALS["UPI_ID"]."&pn=CouponStore&am=".$total."&cu=INR";
  $qr = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=".urlencode($upiLink);

  $msg = "🧾 *Order ID:* `$orderId`\n".
         "Qty: *$qty*\n".
         "Price per coupon: *₹$ppu*\n".
         "Total: *₹$total*\n\n".
         "Pay to: `$GLOBALS[UPI_ID]`\n".
         "Scan QR below and then send *UTR number*.";
  tg("sendPhoto", ["chat_id"=>$chat_id, "photo"=>$qr, "caption"=>$msg, "parse_mode"=>"Markdown"]);
  exit;
}

// If looks like UTR (alphanumeric 6+)
if(preg_match('/^[A-Za-z0-9]{6,}$/', $text)){
  // attach UTR to last order of this user
  foreach(array_reverse($store["orders"], true) as $oid=>$o){
    if($o["user"]==$chat_id && !$o["utr"]){
      $store["orders"][$oid]["utr"] = $text;
      saveStore($store);

      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ UTR received. Verification in progress…"]);
      // notify admin
      $a = "🆕 Payment to verify\nOrder: $oid\nUser: $chat_id\nQty: {$o['qty']}\nTotal: ₹{$o['total']}\nUTR: $text\n\nApprove: /approve $oid";
      tg("sendMessage", ["chat_id"=>$GLOBALS["ADMIN_ID"], "text"=>$a]);
      exit;
    }
  }
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ No pending order found. Send quantity first."]);
  exit;
}

// ===== ADMIN APPROVE =====
if($from_id==$ADMIN_ID && strpos($text,"/approve")==0){
  $oid = trim(str_replace("/approve","",$text));
  if(!isset($store["orders"][$oid])){
    tg("sendMessage", ["chat_id"=>$ADMIN_ID, "text"=>"❌ Order not found."]);
    exit;
  }
  $order = $store["orders"][$oid];
  if($order["approved"]){
    tg("sendMessage", ["chat_id"=>$ADMIN_ID, "text"=>"Already approved."]);
    exit;
  }

  // deliver coupons
  $delivered = [];
  for($i=0;$i<$order["qty"];$i++){
    $c = nextCoupon();
    if(!$c) break;
    $delivered[] = $c;
    $store["used"][] = $c;
  }
  $store["orders"][$oid]["approved"] = true;
  saveStore($store);

  if(empty($delivered)){
    tg("sendMessage", ["chat_id"=>$ADMIN_ID, "text"=>"⚠️ Coupon pool empty. Add more in bot.php."]);
    exit;
  }

  // Send to user with tap-to-copy
  $lines = implode("\n", $delivered);
  $kb = ["inline_keyboard"=>[]];
  foreach($delivered as $c){
    $kb["inline_keyboard"][] = [[ "text"=>"📋 Copy $c", "callback_data"=>"copy:$c" ]];
  }
  tg("sendMessage", [
    "chat_id"=>$order["user"],
    "text"=>"🎉 *Your Coupons*\n\n$lines\n\nTap to copy 👇",
    "parse_mode"=>"Markdown",
    "reply_markup"=>$kb
  ]);
  tg("sendMessage", ["chat_id"=>$ADMIN_ID, "text"=>"✅ Delivered Order $oid"]);
  exit;
}

// Callback for copy
if(isset($update["callback_query"])){
  $cid = $update["callback_query"]["from"]["id"];
  $data = $update["callback_query"]["data"];
  if(strpos($data,"copy:")==0){
    $code = substr($data,5);
    tg("answerCallbackQuery", ["callback_query_id"=>$update["callback_query"]["id"], "text"=>"Copied!"]);
    tg("sendMessage", ["chat_id"=>$cid, "text"=>$code]); // Telegram allows tap-to-copy on plain text
  }
}
