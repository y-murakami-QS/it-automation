<?php
//   Copyright 2019 NEC Corporation
//
//   Licensed under the Apache License, Version 2.0 (the "License");
//   you may not use this file except in compliance with the License.
//   You may obtain a copy of the License at
//
//       http://www.apache.org/licenses/LICENSE-2.0
//
//   Unless required by applicable law or agreed to in writing, software
//   distributed under the License is distributed on an "AS IS" BASIS,
//   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//   See the License for the specific language governing permissions and
//   limitations under the License.
//
/**
 * 【処理内容】
 *    資材自動連携機能(DSC)
 *      資材管理メニューで管理している資材をITAの各種メニューに自動連携する。
 */

if( empty($root_dir_path) ){
    $root_dir_temp = array();
    $root_dir_temp = explode('ita-root', dirname(__FILE__));
    $root_dir_path = $root_dir_temp[0] . 'ita-root';
}

define('ROOT_DIR_PATH',         $root_dir_path);
require_once ROOT_DIR_PATH      . '/libs/backyardlibs/material/ky_material_env.php';
require_once MATERIAL_LIB_PATH  . 'ky_material_classes.php';
require_once MATERIAL_LIB_PATH  . 'ky_material_functions.php';
require_once COMMONLIBS_PATH    . 'common_php_req_gate.php';

try{
    $normalFlg = true;          // 正常判定用フラグ
    $errMsg = NULL;             // エラーメッセージ
    $dscResourceCnt = 0;
    $errorFlg = false;

    $logPrefix = basename( __FILE__, '.php' ) . '_';
    $tmpDir = "";

    if(LOG_LEVEL === 'DEBUG'){
        // 処理開始ログ
        outputLog($objMTS->getSomeMessage('ITAMATERIAL-STD-10001', basename( __FILE__, '.php' )));
    }

    //////////////////////////
    // インターフェース情報テーブルを検索
    //////////////////////////
    $materialIfInfoTable = new MaterialIfInfoTable($objDBCA, $db_model_ch);
    $sql = $materialIfInfoTable->createSselect("WHERE DISUSE_FLAG = '0'");

    // SQL実行
    $result = $materialIfInfoTable->selectTable($sql);
    if(!is_array($result)){
        $msg = $objMTS->getSomeMessage('ITAMATERIAL-ERR-5001', $result);
        outputLog($msg);
        throw new Exception();
    }
    $materialIfInfoArray = $result;

    // インターフェース情報が1件ではない場合はエラー
    if(1 != count($materialIfInfoArray)) {
        $msg = $objMTS->getSomeMessage('ITAMATERIAL-ERR-5002');
        outputLog($msg);
        throw new Exception();
    }

    // リモートリポジトリURLとクローンリポジトリが設定されていない場合はエラー
    if("" == $materialIfInfoArray[0]['REMORT_REPO_URL'] || "" == $materialIfInfoArray[0]['CLONE_REPO_DIR']) {
        if(LOG_LEVEL === 'DEBUG'){
            $msg = $objMTS->getSomeMessage('ITAMATERIAL-ERR-5002');
            outputLog($msg);
        }
        throw new Exception();
    }

    // クローンリポジトリにGitが設定されていない場合はエラー
    if(!file_exists($materialIfInfoArray[0]['CLONE_REPO_DIR'] . "/.git")) {
        if(LOG_LEVEL === 'DEBUG'){
            $msg = $objMTS->getSomeMessage('ITAMATERIAL-ERR-5003');
            outputLog($msg);
        }
        throw new Exception();
    }

    //////////////////////////
    // 資材紐付け管理テーブルを検索
    //////////////////////////
    $materialLinkageDscTable = new MaterialLinkageDscTable($objDBCA, $db_model_ch);
    $sql = $materialLinkageDscTable->createSselect("WHERE DISUSE_FLAG = '0'");

    // SQL実行
    $result = $materialLinkageDscTable->selectTable($sql);
    if(!is_array($result)){
        $msg = $objMTS->getSomeMessage('ITAMATERIAL-ERR-5001', $result);
        outputLog($msg);
        throw new Exception();
    }
    $materialLinkageArray = $result;

    //////////////////////////
    // ファイル管理ビューを検索
    //////////////////////////
    $fileManegementNewestView = new FileManegementNewestView($objDBCA, $db_model_ch);
    $sql = $fileManegementNewestView->createSselect("WHERE DISUSE_FLAG = '0'");

    // SQL実行
    $result = $fileManegementNewestView->selectTable($sql);
    if(!is_array($result)){
        $msg = $objMTS->getSomeMessage('ITAMATERIAL-ERR-5001', $result);
        outputLog($msg);
        throw new Exception();
    }
    $fileManegementNewestArray = $result;

    // 資材紐付け管理テーブルの件数分ループ
    foreach($materialLinkageArray as $materialLinkageData) {

        // 該当のファイル管理データを特定する（資材管理にあるファイル）
        $matchFlg = false;
        $fileNameFullpath = "";
        $fileName = "";
        
        foreach($fileManegementNewestArray as $fileManegementNewestData) {

            // ファイルIDが一致した場合
            if($materialLinkageData['FILE_ID'] == $fileManegementNewestData['FILE_ID']){

                // リビジョン指定がある場合はリビジョンの確認も行う
                if("" != $materialLinkageData['CLOSE_REVISION_ID']){
                    if($fileManegementNewestData['FILE_M_ID'] == $materialLinkageData['CLOSE_REVISION_ID']) {
                        $returnFileDir      = UPLOADFILES_PATH . "" . "RETURN_FILE/" . sprintf("%010d", $fileManegementNewestData['FILE_M_ID']). "/";
                        $fileNameFullpath   = $returnFileDir . $fileManegementNewestData['RETURN_FILE'];
                        $fileName           = $fileManegementNewestData['RETURN_FILE'];
                        $matchFlg           = true;
                        break;
                    }
                }
                else{
                    $fileNameFullpath   = $materialIfInfoArray[0]['CLONE_REPO_DIR'] . $fileManegementNewestData['FILE_NAME_FULLPATH'];
                    $fileName           = $fileManegementNewestData['RETURN_FILE'];
                    $matchFlg           = true;
                    break;
                }
            }
        }

        // ファイル管理ビューの該当データが0件の場合
        if(false === $matchFlg){
            if(LOG_LEVEL === 'DEBUG'){
                outputLog($objMTS->getSomeMessage('ITAMATERIAL-ERR-5008', $materialLinkageData['ROW_ID']));
            }
            // 次のデータへ
            $errorFlg = true;
            continue;
        }

        // 資材のBase64エンコードを取得する
        if(file_exists($fileNameFullpath)){
            $base64File = base64_encode(file_get_contents($fileNameFullpath));
        }
        else{
            if(LOG_LEVEL === 'DEBUG'){
                outputLog($objMTS->getSomeMessage('ITAMATERIAL-ERR-5008', $materialLinkageData['ROW_ID']));
            }
            $errorFlg = true;
            continue;
        }

        //////////////////////////
        // DSCのコンフィグ素材集に連携する場合
        //////////////////////////
        
        // DSCのコンフィグ素材集に連携処理を行う
        $result = linkDscResource($materialLinkageData, $fileName, $base64File);
        if($result !== true){
            $errorFlg = true;
        }
        continue;
    }

    if(LOG_LEVEL === 'DEBUG'){
        // 件数ログ出力
        outputLog($objMTS->getSomeMessage('ITAMATERIAL-STD-10008',array(strval($dscResourceCnt),
                                                                       )
                                         )
                 );
        if(true === $errorFlg){
            throw new Exception();
        }
        // 終了ログ出力
        outputLog($objMTS->getSomeMessage('ITAMATERIAL-STD-10002', basename( __FILE__, '.php' )));
    }
}
catch(Exception $e){
    if(LOG_LEVEL === 'DEBUG'){
        // 終了ログ出力
        outputLog($objMTS->getSomeMessage('ITAMATERIAL-STD-10003', basename( __FILE__, '.php' )));
    }
}

/**
 * DSCコンフィグ素材集連携処理
 * 
 */
function linkDscResource($materialLinkageData, $fileName, $base64File) {

    global $objMTS, $objDBCA, $db_model_ch, $dscResourceCnt;
    $tranStartFlg = false;
    $dscResourceTable = new DscResourceTable($objDBCA, $db_model_ch);
    $cntFlg = false;

    try{
        // トランザクション開始
        $result = $objDBCA->transactionStart();
        if(false === $result){
            $msg = $objMTS->getSomeMessage('ITAMATERIAL-ERR-5001', array($result));
            outputLog($msg);
            throw new Exception();
        }
        $tranStartFlg = true;

        //////////////////////////
        // コンフィグ素材集テーブルを検索
        //////////////////////////
        $sql = $dscResourceTable->createSselect("WHERE DISUSE_FLAG = '0'");
        // SQL実行
        $result = $dscResourceTable->selectTable($sql);
        if(!is_array($result)){
            $msg = $objMTS->getSomeMessage('ITAMATERIAL-ERR-5001', $result);
            outputLog($msg);
            throw new Exception();
        }
        $dscResourceArray = $result;
        $matchFlg = false;

        // コンフィグ素材集テーブルの件数分ループ
        foreach($dscResourceArray as $key => $dscResource) {

            // 資材名が一致するデータを検索
            if($materialLinkageData['MATERIAL_LINK_NAME'] == $dscResource['RESOURCE_MATTER_NAME']) {
                $updateData = $dscResource;
                $matchFlg = true;
                break;
            }
        }

        // 資材名が一致するデータがあった場合
        if(true === $matchFlg) {

            $updateFlg = true;
            // 資材が一致した場合
            if($fileName == $updateData['RESOURCE_MATTER_FILE']){
                $materialPath = DSC_RESOURCE_PATH . sprintf("%010d", $updateData['RESOURCE_MATTER_ID']) . "/" . $updateData['RESOURCE_MATTER_FILE'];
                if(file_exists($materialPath)){
                    // 資材に変更がない場合は更新しない
                    $materialBase64 = base64_encode(file_get_contents($materialPath));
                    if($base64File == $materialBase64){
                        $updateFlg = false;
                    }
                }
            }
            
            if(true === $updateFlg){
                $cntFlg = true;
                // 更新する
                $updateData['RESOURCE_MATTER_FILE']      = $fileName;                          // コンフィグ素材
                $updateData['LAST_UPDATE_USER'] = USER_ID_MATERIAL_LINKAGE;                    // 最終更新者

                //////////////////////////
                // コンフィグ素材集テーブルを更新
                //////////////////////////
                $result = $dscResourceTable->updateTable($updateData, $jnlSeqNo);
                if(true !== $result){
                    $msg = $objMTS->getSomeMessage('ITAMATERIAL-ERR-5001', $result);
                    outputLog($msg);
                    throw new Exception();
                }

                $upFilePath     = DSC_RESOURCE_PATH . sprintf("%010d", $updateData['RESOURCE_MATTER_ID']) . "/";
                $upJnlFilePath  = $upFilePath . "old/" . sprintf("%010d", $jnlSeqNo) . "/";;

                // ファイルを格納パスに配置する
                $result = deployUploadFile($objMTS, $base64File, $fileName, $upFilePath, $upJnlFilePath);

                if(true !== $result){
                    outputLog($result);
                    throw new Exception();
                }
            }
        }
        // 資材名が一致するデータが無かった場合
        else {
            $cntFlg = true;
            // 登録する
            $insertData = array();
            $insertData['RESOURCE_MATTER_NAME'] = $materialLinkageData['MATERIAL_LINK_NAME'];  // コンフィグ名
            $insertData['RESOURCE_MATTER_FILE'] = $fileName;                                   // コンフィグ素材
            $insertData['DISUSE_FLAG']          = "0";                                         // 廃止フラグ
            $insertData['LAST_UPDATE_USER']     = USER_ID_MATERIAL_LINKAGE;                    // 最終更新者

            //////////////////////////
            // コンフィグ素材集テーブルに登録
            //////////////////////////
            $result = $dscResourceTable->insertTable($insertData, $seqNo, $jnlSeqNo);
            if(true !== $result){
                $msg = $objMTS->getSomeMessage('ITAMATERIAL-ERR-5001', $result);
                outputLog($msg);
                throw new Exception();
            }

            $upFilePath     = DSC_RESOURCE_PATH . sprintf("%010d", $seqNo) . "/";
            $upJnlFilePath  = $upFilePath . "old/" . sprintf("%010d", $jnlSeqNo). "/";;

            // ファイルを格納パスに配置する
            $result = deployUploadFile($objMTS, $base64File, $fileName, $upFilePath, $upJnlFilePath);

            if(true !== $result){
                outputLog($result);
                throw new Exception();
            }
        }

        // コミット
        $result = $objDBCA->transactionCommit();
        if(false === $result){
            $msg = $objMTS->getSomeMessage('ITAMATERIAL-ERR-5001', $result);
            outputLog($msg);
            throw new Exception();
        }
        $tranStartFlg = false;

        if(true === $cntFlg){
            $dscResourceCnt ++;
        }

        return true;
    }
    catch(Exception $e){
        // ロールバック
        if(true === $tranStartFlg){
            $objDBCA->transactionRollback();
        }
        return false;
    }
}