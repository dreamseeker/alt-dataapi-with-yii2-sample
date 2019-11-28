<?php

namespace app\controllers;

use app\models\MtAsset;
use app\models\MtAssetMeta;
use app\models\MtBlog;
use app\models\MtCategory;
use app\models\MtCd;
use app\models\MtCf;
use app\models\MtContentType;
use yii\web\Controller;

// MTSerialize クラスのファイルを読み込み
require_once MT7_DIR . 'php/lib/MTSerialize.php';


/**
 * Movable Type 7 のデータベースを利用した、代替 DataAPI 向けのコントローラー。
 */
class MtController extends Controller
{
    /**
     * @var int ターゲットのコンテンツタイプ ID。
     * URL パラメータとしてセットされる想定。
     */
    public $contentTypeId;

    /**
     * @var instansce MTSerialize クラスのインスタンス。
     * Movable Type 標準のシリアライズ関連のクラス。
     */
    public $mtSerialize;


    /**
     * Initialize
     *
     * @return string
     */
    public function init(){
        parent::init();

        // URL パラメータを取得
        $request = \Yii::$app->request;
        $this->contentTypeId = $request->get('contentTypeId');

        // MT のシリアライズ関連クラスを初期化
        $this->mtSerialize = \MTSerialize::get_instance();
    }


    /**
     * Hello World
     *
     * @return string
     *
     * https://example.docker/yii2/mt/hello-world
     * https://example.docker/yii2/api/helloWorld
     */
    public function actionHelloWorld(){
        return 'Hello World';
    }


    /**
     * 「ブログ」の取得・出力
     *
     * @return string
     *
     * https://example.docker/yii2/mt/blog-index
     * https://example.docker/yii2/api/blog
     */
    public function actionBlogIndex(){
        $blogs = MtBlog::find()
            ->orderBy('blog_id')
            ->asArray()
            ->all();

        return $this->asJson($blogs);
    }


    /**
     * 「コンテンツデータ」の取得・出力
     *
     * @return string
     *
     * https://example.docker/yii2/mt/content-data?contentTypeId=1
     * https://example.docker/yii2/api/contentData/1
     */
    public function actionContentData(){
        // データの格納に利用する配列を初期化
        $items          = [];
        $imageIds       = [];
        $categoryIds    = [];

        // コンテンツフィールドの取得
        $contentFields = $this->getContentFieldData();

        // コンテンツタイプの「データ識別ラベル」に指定されたフィールドのユニーク ID を取得
        $contentTypeDataLabel = $this->getContentTypeDataLabel();

        // 基本クエリをセット
        $query = MtCd::find()
            ->where('cd_content_type_id=:contentTypeId', [':contentTypeId' => $this->contentTypeId]);

        // 総数の取得
        $totalResults = $query->count();

        // コンテンツデータの取得
        $results = $query
            ->orderBy(['cd_authored_on' => SORT_ASC])
            ->asArray()
            ->all();

        // 取得したコンテンツデータのループ処理
        foreach ($results as $row){
            // 整形後の cd_data を格納する配列を初期化
            $data = [];

            // ラベルを格納するための変数を初期化
            $label = null;

            // cd_data カラムをアンシリアライズ
            $unserializedCdData = $this->mtSerialize->unserialize($row['cd_data']);

            // コンテンツデータをフィールドごとにループ処理
            foreach ($unserializedCdData as $key => $value) {
                // 「入力フォーマット」に関するデータを除外
                if(!preg_match('/\_convert\_breaks$/', $key)){
                    // コンテンツフィールドの配列から、ループ中のフィールド情報のインデックス番号を取得
                    $keyIndex = array_search($key, array_column($contentFields, 'id'));

                    // 入力値をセット（必要に応じて後続の処理で上書き）
                    $data[] = [
                        'id'    => $key,
                        'label' => $contentFields[$keyIndex]['name'],
                        'type'  => $contentFields[$keyIndex]['type'],
                        'data'  => $value,
                    ];

                    // フィールドタイプごとの追加処理
                    if (in_array($contentFields[$keyIndex]['type'], ['categories', 'asset_image'])) {
                        // カテゴリー・画像
                        switch ($contentFields[$keyIndex]['type']){
                            case 'categories':
                                // 重複しないカテゴリ ID をセット
                                $categoryIds = array_unique(array_merge($categoryIds, $value));
                                break;
                            case 'asset_image':
                                // 重複しないアセット ID をセット
                                $imageIds = array_unique(array_merge($imageIds, $value));
                                break;
                        }
                    }

                    // フィールドのユニーク ID が「データ識別ラベル」なら、変数にセット
                    if($contentFields[$keyIndex]['uniqueId'] === $contentTypeDataLabel) {
                        $label = $value;
                    }
                }
            }

            $items[] = [
                'id'            => (int) $row['cd_id'],
                'label'         => $label,
                'data'          => $data,
                'authorId'      => (int) $row['cd_author_id'],
                'blogId'        => (int) $row['cd_blog_id'],
                'basename'      => $row['cd_identifier'],
                'date'          => $row['cd_authored_on'],
                'createdDate'   => $row['cd_created_on'],
                'modifiedDate'  => $row['cd_modified_on'],
            ];
        }

        // 整形後のデータを JSON 形式で出力
        return $this->asJson([
            'items'         => $items,
            'categories'    => $this->getCategoryData($categoryIds),
            'images'        => $this->getAssetImageData($imageIds),
            'totalResults'  => $totalResults,
        ]);
    }


    /**
     * 「コンテンツフィールド」データの取得
     *
     * @return array
     */
    protected function getContentFieldData(){
        // レスポンスデータを格納する配列を初期化
        $response = [];

        // コンテンツフィールドの取得
        $results = MtCf::find()
            ->where('cf_content_type_id=:contentTypeId', [':contentTypeId' => $this->contentTypeId])
            ->asArray()
            ->all();

        // コンテンツフィールドのループ処理
        foreach ($results as $row){
            // 必要なデータを $response に追加
            $response[] = [
                'id'            => (int) $row['cf_id'],
                'name'          => $row['cf_name'],
                'type'          => $row['cf_type'],
                'blogId'        => (int) $row['cf_blog_id'],
                'contentTypeId' => (int) $row['cf_content_type_id'],
                'uniqueId'      => $row['cf_unique_id'],
            ];
        }

        return $response;
    }


    /**
     * 「データ識別ラベル」にセットされたフィールドのユニーク ID を取得
     *
     * @return string
     */
    protected function getContentTypeDataLabel(){
        // コンテンツタイプの取得
        $result = MtContentType::find()
            ->where('content_type_id=:contentTypeId', [':contentTypeId' => $this->contentTypeId])
            ->one();

        // 「データ識別ラベル」にセットされたフィールドのユニーク ID を返す
        return $result->content_type_data_label;
    }


    /**
     * 「カテゴリ」データの取得
     * @param array $ids 取得対象となる category_id の配列
     * @return array
     */
    protected function getCategoryData($ids){
        // レスポンスデータを格納する配列を初期化
        $response = [];

        // カテゴリの取得
        $results = MtCategory::find()
            ->where(['category_id' => $ids])
            ->asArray()
            ->all();

        // カテゴリのループ処理
        foreach ($results as $row){
            // 必要なデータを $response に追加
            $response[] = [
                'id'            => (int) $row['category_id'],
                'label'         => $row['category_label'],
                'basename'      => $row['category_basename'],
                'parentId'      => (int) $row['category_parent'],
                'blogId'        => (int) $row['category_blog_id'],
                'categorySetId' => (int) $row['category_category_set_id'],
            ];
        }

        return $response;
    }


    /**
     * 「アセット」データの取得
     * @param array $ids 取得対象となる asset_id の配列
     * @return array
     */
    protected function getAssetImageData($ids){
        // レスポンスデータを格納する配列を初期化
        $response = [];

        // サブクエリをセット
        $assetMetaQuery = MtAssetMeta::find();
        $blogQuery      = MtBlog::find();

        // アセットの取得
        $results = MtAsset::find()
            ->select([
                '*',
                'width'     => 'am.asset_meta_vinteger',
                'height'    => 'am2.asset_meta_vinteger',
                'blogUrl'   => 'b.blog_site_url',
            ])
            ->where(['asset_id' => $ids])
            ->leftJoin(['am' => $assetMetaQuery], 'am.asset_meta_asset_id = asset_id AND am.asset_meta_type = "image_width"')
            ->leftJoin(['am2' => $assetMetaQuery], 'am2.asset_meta_asset_id = asset_id AND am2.asset_meta_type = "image_height"')
            ->leftJoin(['b' => $blogQuery], 'b.blog_id = asset_blog_id')
            ->asArray()
            ->all();

        // アセットのループ処理
        foreach ($results as $row){
            // ファイルの URL を整形
            $fileUrl = $row['blogUrl'] . preg_replace('/^%r\//', '', $row['asset_url']);

            // 必要なデータを $response に追加
            $response[] = [
                'id'        => (int) $row['asset_id'],
                'label'     => $row['asset_label'],
                'url'       => $fileUrl,
                'blogId'    => (int) $row['asset_blog_id'],
                'width'     => (int) $row['width'],
                'height'    => (int) $row['height'],
                'class'     => $row['asset_class'],
                'fileExt'   => $row['asset_file_ext'],
            ];
        }

        return $response;
    }
}
