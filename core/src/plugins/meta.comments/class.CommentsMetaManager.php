<?php
/**
 * Created by JetBrains PhpStorm.
 * User: admin
 * Date: 11/09/13
 * Time: 11:14
 * To change this template use File | Settings | File Templates.
 */

define("AJXP_META_SPACE_COMMENTS", "AJXP_META_SPACE_COMMENTS");

class CommentsMetaManager extends AJXP_Plugin
{
    /**
     * @var AbstractAccessDriver
     */
    private $accessDriver;
    /**
     * @var MetaStoreProvider
     */
    private $metaStore;
    /**
     * @var AJXP_FeedStore
     */
    private $feedStore;

    private $storageMode;

    public function initMeta($accessDriver)
    {
        $this->accessDriver = $accessDriver;
        $feed = AJXP_PluginsService::getInstance()->getUniqueActivePluginForType("feed");
        if ($feed) {
            $this->storageMode = "FEED";
            $this->feedStore = $feed;
        } else {
            $store = AJXP_PluginsService::getInstance()->getUniqueActivePluginForType("metastore");
            if ($store === false) {
                throw new Exception("The 'meta.comments' plugin requires at least one active 'metastore' plugin");
            }
            $this->metaStore = $store;
            $this->storageMode = "METASTORE";
        }
    }
    /**
     * @param AJXP_Node $ajxpNode
     */
    public function mergeMeta($ajxpNode)
    {
        $ajxpNode->mergeMetadata(array("ajxp_has_comments_feed" => "true"));
        if ($ajxpNode->retrieveMetadata(AJXP_META_SPACE_COMMENTS, false) != null) {
        }

    }

    /**
     *
     * @param AJXP_Node $oldFile
     * @param AJXP_Node $newFile
     * @param Boolean $copy
     */
    public function moveMeta($oldFile, $newFile = null, $copy = false)
    {
        if($oldFile == null) return;
        $feedStore = AJXP_PluginsService::getInstance()->getUniqueActivePluginForType("feed");
        if ($feedStore !== false) {
            $feedStore->updateMetaObject(ConfService::getRepository()->getId(), $oldFile->getPath(), ($newFile!=null?$newFile->getPath():null), $copy);
            return;
        }

        if(!$copy && $this->metaStore->inherentMetaMove()) return;

        $oldMeta = $this->metaStore->retrieveMetadata($oldFile, AJXP_META_SPACE_COMMENTS);
        if (!count($oldMeta)) {
            return;
        }
        // If it's a move or a delete, delete old data
        if (!$copy) {
            $this->metaStore->removeMetadata($oldFile, AJXP_META_SPACE_COMMENTS);
        }
        // If copy or move, copy data.
        if ($newFile != null) {
            $this->metaStore->setMetadata($newFile, AJXP_META_SPACE_COMMENTS, $oldMeta);
        }
    }


    /**
     * @param String $actionName
     * @param Array $httpVars
     * @param Array $fileVars
     */
    public function switchActions($actionName, $httpVars, $fileVars)
    {
        $userSelection = new UserSelection();
        $userSelection->initFromHttpVars($httpVars);
        $uniqNode = $userSelection->getUniqueNode($this->accessDriver);
        $feedStore = AJXP_PluginsService::getInstance()->getUniqueActivePluginForType("feed");
        $existingFeed = $uniqNode->retrieveMetadata(AJXP_META_SPACE_COMMENTS, false);
        if ($existingFeed == null) {
            $existingFeed = array();
        }
        $mess = ConfService::getMessages();
        switch ($actionName) {

            case "post_comment":

                $uId = AuthService::getLoggedUser()->getId();
                $limit = $this->getFilteredOption("COMMENT_SIZE_LIMIT");
                if (!empty($limit)) {
                    $content = substr(AJXP_Utils::decodeSecureMagic($httpVars["content"]), 0, $limit);
                } else {
                    $content = AJXP_Utils::decodeSecureMagic($httpVars["content"]);
                }
                $com = array(
                    "date"      => time(),
                    "author"    => $uId,
                    "content"   => $content
                );
                $existingFeed[] = $com;
                if ($feedStore!== false) {
                    $feedStore->persistMetaObject(
                        $uniqNode->getPath(),
                        base64_encode($content),
                        $uniqNode->getRepositoryId(),
                        $uniqNode->getRepository()->securityScope(),
                        $uniqNode->getRepository()->getOwner(),
                        AuthService::getLoggedUser()->getId(),
                        AuthService::getLoggedUser()->getGroupPath());
                } else {
                    $uniqNode->removeMetadata(AJXP_META_SPACE_COMMENTS, false);
                    $uniqNode->setMetadata(AJXP_META_SPACE_COMMENTS, $existingFeed, false);
                }
                HTMLWriter::charsetHeader("application/json");
                $com["hdate"] = AJXP_Utils::relativeDate($com["date"], $mess);
                echo json_encode($com);

                break;

            case "load_comments_feed":

                HTMLWriter::charsetHeader("application/json");
                if ($feedStore !== false) {
                    $sortBy = isSet($httpVars["sort_by"])?AJXP_Utils::decodeSecureMagic($httpVars["sort_by"]):"date";
                    $sortDir = isSet($httpVars["sort_dir"])?AJXP_Utils::decodeSecureMagic($httpVars["sort_dir"]):"asc";
                    $offset = isSet($httpVars["offset"]) ? intval($httpVars["offset"]) : 0;
                    $limit = isSet($httpVars["limit"]) ? intval($httpVars["limit"]) : 100;
                    $data = $feedStore->findMetaObjectsByIndexPath(ConfService::getRepository()->getId(), $uniqNode->getPath(), AuthService::getLoggedUser()->getId(), AuthService::getLoggedUser()->getGroupPath(), $offset, $limit, $sortBy, $sortDir);
                    $theFeed = array();
                    foreach ($data as $stdObject) {
                        $rPath = substr($stdObject->path, strlen($uniqNode->getPath()));
                        if($rPath == false && $stdObject->path == $uniqNode->getPath()) $rPath = "";
                        $rPath = ltrim($rPath, "/");
                        $newItem = array(
                            "date"      =>$stdObject->date,
                            "hdate"     => AJXP_Utils::relativeDate($stdObject->date, $mess),
                            "author"    => $stdObject->author,
                            "content"   => base64_decode($stdObject->content),
                            "path"      => $stdObject->path,
                            "rpath"     => $rPath,
                            "uuid"      => $stdObject->uuid
                        );
                        if (isSet($previous) && $previous["author"] == $newItem["author"] &&  $previous["path"] == $newItem["path"] && $previous["hdate"] == $newItem["hdate"] ) {
                            $theFeed[count($theFeed) - 1]["content"].= '<br>'.$newItem["content"];

                        } else {
                            $theFeed[] = $newItem;
                        }
                        $previous = $newItem;
                    }
                    echo json_encode($theFeed);
                } else {
                    foreach ($existingFeed as &$item) {
                        $item["hdate"] = AJXP_Utils::relativeDate($item["date"], $mess);
                    }
                    echo json_encode($existingFeed);
                }

                break;

            case "delete_comment":

                $data = json_decode($httpVars["comment_data"], true);
                if ($feedStore === false) {
                    $reFeed = array();
                    if($data["author"] != AuthService::getLoggedUser()->getId()) break;
                    foreach ($existingFeed as $fElement) {
                        if ($fElement["date"] == $data["date"] && $fElement["author"] == $data["author"] && $fElement["content"] == $data["content"]) {
                            continue;
                        }
                        $fElement["hdate"] = AJXP_Utils::relativeDate($fElement["date"], $mess);
                        $reFeed[] = $fElement;
                    }
                    $uniqNode->removeMetadata(AJXP_META_SPACE_COMMENTS, false);
                    $uniqNode->setMetadata(AJXP_META_SPACE_COMMENTS, $reFeed, false);
                    HTMLWriter::charsetHeader("application/json");
                    echo json_encode($reFeed);
                } else {
                    $feedStore->dismissAlertById($data["uuid"], 1);
                }

                break;

            default:
                break;
        }

    }

}
