<?php
// Disposable fixture only. Real integrations MUST use their own existing authentication and database.
if (getenv('PB_SSO_QA') !== '1' || !str_contains((string)getenv('PB_SSO_QA_CONFIG'),'brief-provisioning-')) { http_response_code(404); exit; }
$config=json_decode(file_get_contents(getenv('PB_SSO_QA_CONFIG')),true,512,JSON_THROW_ON_ERROR);
require $config['helper'];
session_name('sso_external_qa'); session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Lax']); session_start();
$profiles=['client'=>['id'=>'user-42','email'=>'ada@example.test','verified'=>true],'unverified'=>['id'=>'user-43','email'=>'ada@example.test','verified'=>false],'staff'=>['id'=>'staff-44','email'=>'owner@example.test','verified'=>true]];
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/mock-login') { $profile=$_GET['profile']??'client'; if (!isset($profiles[$profile])) { http_response_code(403); exit; } session_regenerate_id(true); $_SESSION=['user'=>$profile,'csrf'=>bin2hex(random_bytes(24))]; header('Location: /account',true,303); exit; }
if (!isset($_SESSION['user'],$profiles[$_SESSION['user']])) { http_response_code(401); exit; }
header('Cache-Control: private, no-store'); header('Referrer-Policy: no-referrer');
if ($path==='/account' || $path==='/') { echo '<!doctype html><title>Local authenticated account</title><form method="post" action="/open"><input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf'],ENT_QUOTES).'"><button>Open project</button></form>'; exit; }
if ($path!=='/open' || $_SERVER['REQUEST_METHOD']!=='POST' || !is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) { http_response_code(403); exit; }
$user=$profiles[$_SESSION['user']];
try {
    $api=new ProjectBriefClientSso($config['origin'],$config['token'],$config['ca']);
    $handoff=$api->issue($config['projectId'],$user['id'],$user['email'],$user['verified']);
    $api->transferPage($handoff);
} catch (Throwable $exception) { http_response_code(403); echo '<!doctype html><title>Email sign-in available</title><a href="'.htmlspecialchars($config['origin'].'/project/'.$config['projectId'],ENT_QUOTES).'">Sign in by email</a>'; }
