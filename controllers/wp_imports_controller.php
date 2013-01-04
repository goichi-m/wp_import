<?php
/******************************************************
**
** WP Import
** wp_import_controller.php
**
** @description:コントローラーファイル
** @author: G.Maniwa
** @date: 2012-12-24
**
*****************************************************/
class WpImportsController extends AppController {




	/********************************************
	*　設定
	********************************************/
	var $name = 'WpImports';
	var $helpers = array(BC_TEXT_HELPER, BC_FORM_HELPER, BC_ARRAY_HELPER);
	var $uses = array('Blog.BlogCategory','Blog.BlogPost','Blog.BlogContent','Blog.BlogTag','Blog.BlogComment');
	var $components = array('BcAuth','Cookie','BcAuthConfigure');
	var $crumbs = array(array('name' => 'WPインポート管理', 'url' => array('controller' => 'wp_import', 'action' => 'index')));


	

	/********************************************
	*　カテゴリの一覧表示を行う
	********************************************/
	function admin_index() {

		//初期表示
		if(empty($this->data)){

			/* 表示設定 */
			$this->subMenuElements = array('wp_import');
			$this->pageTitle = 'WPインポート　XMLアップロード';
			$this->help = 'wp_import_index';

		//アップロードあり
		}else{

			//データの取りこぼしカウント変数
			$errorPost = 0;
			$errorCategory = 0;
			$errorTag = 0;
			$errorComment = 0;

			//アップロードされたファイルを移動
			$filesPath = WWW_ROOT.'files';
			$savePath = $filesPath.DS.'wpxml';

			//無事にアップロードされていれば処理続行
			if(is_uploaded_file($this->data['WpImport']['xml']['tmp_name'])){

				//日本語ファイルのチェック（リネームして保存させたほうがいいかも）
				if( strlen($this->data['WpImport']['xml']['name']) == mb_strlen($this->data['WpImport']['xml']['name']) ){

					//アップロードするファイルの場所
					$uploadfile = $savePath.DS.basename($this->data['WpImport']['xml']['name']);

					//一時ファイルから、保存場所へ移動
					if (move_uploaded_file($this->data['WpImport']['xml']['tmp_name'], $uploadfile)){

						//パーミッション変更（このあとコロンをアンダースコアにリプレイスする）
						//SimpleXMLで読み込めない為。
						chmod($uploadfile, 0666);
					
					//保存場所へ移動出来なかったら
					} else {
						//失敗
						$this->Session->setFlash("XMLファイルのアップロードに失敗しました。");
						return;
					}

				//日本語が混じるファイルだった
				}else{
					$this->Session->setFlash("XMLファイルのアップロードに失敗しました。");
					return;
				}

			//ファイルがアップロードされてこなかった
			} else {
				$this->Session->setFlash("ファイルを選択してアップロードしてください。");
				return;
			}
			
			//ファイルの読み込み
			$upData = file_get_contents($uploadfile);

			//置き換え実行
			/*
			<wp: </wp:
			<contents: </contents:
			の４つをそのままだとパースできない為
			その他の部分はインポートする必要がないので、無視することにした。
			*/
			$replaceData = str_replace("<wp:", "<wp_", $upData);
			$replaceData = str_replace("</wp:", "</wp_", $replaceData);
			$replaceData = str_replace("<content:", "<content_", $replaceData);
			$replaceData = str_replace("</content:", "</content_", $replaceData);

			//移動したファイルを書き換え
			if(!file_put_contents($uploadfile, $replaceData)){
				//書き換えエラー
				$this->Session->setFlash("ファイルの整形に失敗しました。");
				return;

			//書き換え成功　XMLの読み込み開始
			}else{

				//SimpleXMLで読み込み
				$xml = simplexml_load_file($uploadfile);


				/*
				//　データ格納用にブログコンテンツを新規生成する
				//----------------------------------------------------*/

				//ブログ名（半角のみ）
				$blogTitle = "wp_import";

				//同名ブログの数
				$blogContentCount = 0;

				//既存のブログ名とかぶらなか確認する。
				$blogContentCount = $this->BlogContent->find('count',array(
						'title' => $blogTitle
					));

				//すでに同名のものがある場合は、名前のあとに番号をふる。
				if($blogContentCount > 0){
					$blogContentCount++;
					$blogTitle = $blogTitle.'_'.$blogContentCount;
				}

				//新規作成用のブログ情報格納
				//基本設定部分
				$this->data['BlogContent']['title'] = (string)$xml->channel->title;
				$this->data['BlogContent']['name'] = (string)$blogTitle;
				$this->data['BlogContent']['description'] = (string)$xml->channel->description;
				$this->data['BlogContent']['exclude_search'] = "";

				//オプション部分
				$this->data['BlogContent']['status'] = 0;
				$this->data['BlogContent']['list_count'] = 10;
				$this->data['BlogContent']['list_direction'] = "DESC";
				$this->data['BlogContent']['feed_count'] = 10;
				$this->data['BlogContent']['comment_use'] = 1;
				$this->data['BlogContent']['auth_captcha'] = 1;
				$this->data['BlogContent']['tag_use'] = 1;
				$this->data['BlogContent']['widget_area'] = "";
				$this->data['BlogContent']['layout'] = "default";
				$this->data['BlogContent']['template'] = "default";

				//上記内容でコンテンツを登録する
				$this->BlogContent->create();

				if($this->BlogContent->save($this->data)){
					//いま作成したばかりのブログのidを取得する
					$blogContentId = $this->BlogContent->getLastInsertId();

				//生成失敗
				}else{
					$this->Session->setFlash("インポート先のブログ生成に失敗しました。");
					return;
				}




				/*
				//　カテゴリのインポート
				//----------------------------------------------------*/

				//カテゴリカウント用
				$i = 0;
				$importCateNum = 0;

				//インポート先のブログID（ここを変えればインポート先を変えれる）
				//とりあえず、今回は先ほど作った格納用ブログに格納する。
				$blogID = $blogContentId; 

				//ツリービヘイビアを解除するので、手動で全てが親なしの状態にする。
				//規則に従って挿入するため、下記のループ処理内で加算処理する。
				$lft = 1;
				$rght = 2;

				//１個ずつ追加していく
				foreach($xml->channel->wp_category as $cate){

					//ツリービヘイビアを解除する（自動では上手く出来ない為）
					$this->BlogCategory->Behaviors->detach('Tree');
					//モデルのキャッシュをオフにする
					$this->BlogCategory->cacheQueries = false;
					//新規でインサートする
					$this->BlogCategory->create();

					//ここでバリデート用に変数を入れておかないとモデルに怒られる
					$this->BlogCategory->validationParams['blogContentId'] = $blogID;

					//カテゴリ名はURLエンコードされてる（日本語の場合を考慮）
					$this->data['BlogCategory']['title'] = urldecode($cate->wp_cat_name);
					$this->data['BlogCategory']['name'] = $cate->wp_category_nicename;

					//一度、カテゴリを全部入れてから、親カテゴリが登録されているかどうかを確認したほうがいいかも。
					//まずはなしで入れておく。
					$this->data['BlogCategory']['parent_id'] = null;

					//ツリービヘイビアの関係があるので、ちょっと変更する。
					if($i != 0){
						$lft = $lft+2;
						$rght = $rght+2;
					}
					$this->data['BlogCategory']['lft'] = $lft;
					$this->data['BlogCategory']['rght'] = $rght;
					$this->data['BlogCategory']['blog_content_id'] = $blogID;
					$this->data['BlogCategory']['no'] = $this->BlogCategory->getMax('no',array('BlogCategory.blog_content_id'=>$blogID))+1;

					//セーブする
					if($this->BlogCategory->save($this->data)){
						
						//いま保存した内容を取得する。
						$newCateData[$i] = $this->BlogCategory->find('first');
						$importCateNum++;

					//保存失敗
					}else{
						//セーブできなかった
						$errorCategory++;

					}

					//加算処理
					$i++;
				}
				//カテゴリここまで




				/*
				//　タグのインポート
				//----------------------------------------------------*/
				//カウント
				$importTagNum = 0;

				//１個ずつ追加していく
				foreach($xml->channel->wp_tag as $tag){

					//新規でインサート
					$this->BlogTag->create();

					//インポートデータの整形
					$this->data['BlogTag']['name'] = (string)$tag->wp_tag_name;

					//タグは全ブログ共通の為、すでに同名のものがあると保存できず失敗する。
					$tagCheck = $this->BlogTag->find('count',array(
							'name' => $this->data['BlogTag']['name']
						));

						if($this->BlogTag->save($this->data)){

							$importTagNum++;

						}else{
							//インポート失敗
							//なおBlogTagモデルのバリデートによって、同名のタグがあれば弾かれる
							//タグは全てのブログで同じタグを利用するので、弾かれる事が多いかも。
							$errorTag++;

						}

				}
				//タグここまで




				/*
				//　記事のインポート
				//----------------------------------------------------*/

				//記事のカウント用
				$importPostNum = 0;

				//コメントのカウント
				$importCommentNum = 0;

				//１個ずつ追加していく
				foreach($xml->channel->item as $item){

					//詳細の配列宣言
					$postDetail = "";

					//WordPressのエクスポートファイルは固定ページもブログ記事も全部まとめて入っているので
					//一度、記事のタイプを確認して、ブログ記事はブログへ入れるようにしなければならない。
					if($item->wp_post_type == 'post'){

						//タグの入る配列を用意しなおす
						$this->data['BlogTag']['BlogTag'] = array();

						//新規でインサート
						$this->BlogPost->create();

						//本文を概要と詳細に分ける
						$moreText = array();

						//記事内容は「<!--more-->」があるかどうかで、本文と詳細の区切りを考える
						if(mb_strpos($item->content_encoded,"<!--more-->")){
							//含まれているので、モアより上を本文、下を詳細にいれる。
							$moreText = mb_split("<!--more-->", $item->content_encoded);

							//配列に入っているので、0番目を本文に、１番目以降は詳細記事へ
							for($c=0;$c<count($moreText);$c++){

								if($c==0){
									$postContents = $moreText[$c];
								}else{
									$postDetail .= $moreText[$c];
								}

							}

						//<!--more-->が存在しない記事の場合
						}else{

							//全てを「詳細」へ入れてしまう。
							$postContents = "";
							$postDetail = $item->content_encoded;

						}

						//公開設定の確認 <wp:status>publish</wp:status>
						if($item->wp_status == "publish"){
							$postStatus = 1; //公開
						}else{
							$postStatus = 0; //非公開
						}

						//カテゴリとタグの確認
						if(count($item->category) != 0){

							//WordPressで１つの記事が複数カテゴリに入っている事があるっぽい。
							for($cateCount = 0; $cateCount < count($item->category); $cateCount++){

								//まずはカテゴリから
								if($item->category[$cateCount]['domain'] == 'category'){
									$cateName = $item->category[$cateCount]; //カテゴリ名
									//カテゴリ名からカテゴリIDを検索する。
									$blogCateData = $this->BlogCategory->find('first',array('conditions'=>array(
											'title' => $cateName,
											'blog_content_id' => $blogID
										)));

									//IDを取る
									if(!empty($blogCateData['BlogCategory']['id'])){
										$this->data['BlogPost']['blog_category_id'] = $blogCateData['BlogCategory']['id'];
									}


								//続いてタグ
								}elseif($item->category[$cateCount]['domain'] == 'post_tag'){

									$tagName = $item->category[$cateCount]; //タグ名
									//タグ名からタグIDを検索する
									$tagData = $this->BlogTag->find('first',array('conditions'=>array(
											'name' => $tagName
										)));
									//IDを取る
									if(!empty($tagData['BlogTag']['id'])){
										$tagId = $tagData['BlogTag']['id'];
										//tagを関連付ける
										$this->data['BlogTag']['BlogTag'][] = $tagId;
									}

								}
							}

						}
						//カテゴリとタグの登録ここまで



						//BlogPostへ保存する内容をここで整形する。
						//挿入内容を整形
						$this->data['BlogPost']['blog_content_id'] = $blogID;
						$this->data['BlogPost']['no'] = $this->BlogPost->getMax('no',array('BlogPost.blog_content_id'=>$blogID))+1;
						$this->data['BlogPost']['name'] = (string)$item->title;
							//タイトルがない場合は無題として登録する。（下書き保存されてタイトル未定のものなど）
							if($this->data['BlogPost']['name'] == ''){
								$this->data['BlogPost']['name'] = '無題';
							}
						$this->data['BlogPost']['content'] = $postContents;
						$this->data['BlogPost']['detail'] = $postDetail;
						$this->data['BlogPost']['status'] = $postStatus;
						$this->data['BlogPost']['posts_date'] = $item->wp_post_date;
						$this->data['BlogPost']['exclude_search'] = 0;
						$this->data['BlogPost']['publish_begin'] = "";
						$this->data['BlogPost']['publish_end'] = "";
						//投稿者を決定する。エクスポートファイルにはテキストで投稿者名が入っているが、
						//baserCMS側に同名のアカウントがあるとは限らないので、ここでこの処理を実行しているユーザーを投稿者とする。
						$user = $this->BcAuth->user();
						$userModel = $this->getUserModel();
						$this->data['BlogPost']['user_id'] = $user[$userModel]['id'];

						//カテゴリIDを入れておかないと検索用のタグ生成でワーニングが出ちゃう模様
						if($this->data['BlogPost']['blog_category_id']){
							$this->data['blog_category_id'] = $this->data['BlogPost']['blog_category_id'];
						}else{
							$this->data['blog_category_id'] = NULL;
						}

						//インサートする為に初期化実行
						$this->BlogPost->create();

						//ここで再び復活
						$this->BlogCategory->Behaviors->attach('Tree');

						//記事の保存
						if($this->BlogPost->save($this->data)){
							//インポート済み件数を加算
							$importPostNum++;

							//この記事のidを取得する
							$blogPostId = $this->BlogPost->getLastInsertId();

							//コメントがあれば配列で取得する。
							$commentNum = count($item->wp_comment);
							if($commentNum > 0){

								//回数分を繰り返してインサートする
								for($co = 0; $co < $commentNum; $co++){

									//配列初期化作戦
									$commentData = array();
									
									//インポートするコメントデータの整形
									$commentData['BlogComment']['blog_content_id'] = $blogID;
									$commentData['BlogComment']['blog_post_id'] = $blogPostId;
									$commentData['BlogComment']['no'] = $this->BlogComment->getMax('no',array('BlogComment.blog_content_id'=>$blogID))+1;
									//承認状態
									if($item->wp_comment[$co]->wp_comment_approved == 1){
										//$this->data['BlogComment']['status'] = 1;
										$commentData['BlogComment']['status'] = 1;
									}else{
										//$this->data['BlogComment']['status'] = 0;
										$commentData['BlogComment']['status'] = 0;
									}
									$commentData['BlogComment']['name'] = (string)$item->wp_comment[$co]->wp_comment_author;
									$commentData['BlogComment']['email'] = (string)$item->wp_comment[$co]->wp_comment_author_email;
									$commentData['BlogComment']['url'] = (string)$item->wp_comment[$co]->wp_comment_author_url;
									$commentData['BlogComment']['message'] = (string)$item->wp_comment[$co]->wp_comment_content;
									$commentData['BlogComment']['created'] = $item->wp_comment[$co]->wp_comment_date;

									//ピンバックでなく、コメントの中身があれば保存実行
									//WordPressはピンバックもコメント扱い・・・。
									if($item->wp_comment->wp_comment_type != 'pingback' && !empty($commentData['BlogComment']['message'])){
	
										//コメントが重複して入っている可能性がある模様
										//ここで一度、重複していたらまとめる処理が必要
										//if(count($commentData['BlogComment']['name']) > 1){
											//var_dump($commentData['BlogComment']['name']);
										//}
										$commentCheck = array();
										
										$commentCheck = $this->BlogComment->findAll(array(
												'message' => $commentData['BlogComment']['message']
												//'blog_content_id' => $blogID,
												//'blog_post_id' => $blogPostId
											)
										);

										//重複回避
										if(empty($commentCheck)){

											//クリエイト実行
											$this->BlogComment->create();

											//同じものがなければ実行
											if($this->BlogComment->save($commentData)){

												//カウント
												$importCommentNum++;

											}else{
												//コメントのインポート失敗件数の加算
												$errorComment++;

											}
										}
									}
								}
							}


						//記事のインポート失敗
						}else{
							//失敗件数の加算
							$errorPost++;
							
						}

					}
					

				}
				//記事ここまで

				$mess = $blogTitle.'を作成し以下の通りインポートを実行しました。<br /><br />';
				if($importCateNum > 0){
					$mess .= '・'.$importCateNum.'件のカテゴリをインポートしました。<br />';
				}
				if($errorCategory >0){
					$mess .= '（ただし'.$errorCategory.'件のカテゴリがインポート出来ませんでした）<br />';
				}

				if($importTagNum > 0){
					$mess .= '・'.$importTagNum.'件のタグをインポートしました。<br />';
				}
				if($errorTag >0){
					$mess .= '（ただし'.$errorTag.'件のタグがインポート出来ませんでした）<br />';
				}

				if($importPostNum > 0){
					$mess .= '・'.$importPostNum.'件の記事をインポートしました。<br />';
				}
				if($errorPost >0){
					$mess .= '（ただし'.$errorPost.'件の記事がインポート出来ませんでした）<br />';
				}

				if($importCommentNum > 0){
					$mess .= '・'.$importCommentNum.'件のコメントをインポートしました。<br />';
				}
				if($errorComment >0){
					$mess .= '（ただし'.$errorComment.'件のコメントがインポート出来ませんでした）<br />';
				}


				//メッセージ
				$this->Session->setFlash($mess);
				$this->redirect(array('plugin'=>'blog','controller'=>'BlogPosts' , 'action' => 'index', $blogID));


			}


		}	

	}

	
}
?>