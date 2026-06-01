<?php

namespace CropTool;

class QueryResponse
{
    public $exists = false;
    public $pageid;
    public $sha1;
    public $mime;
    public $url;
    public $width;
    public $height;
    public $descriptionurl;
    public $pagecount;
    public $categories;
    public $protection = [];

    public function __construct($response=null)
    {
        if (is_null($response)) {
            return;
        }
        foreach ($response->pages as $pageid => $page) {
            if ($pageid == '-1') {
                $this->exists = false;
                return;
            }
            $this->exists = true;
            $this->pageid = $page->pageid ?? null;
            $this->parseImageInfo($page->imageinfo[0]);
            $this->parseCategories($page->categories ?? []);
            $this->parseProtection($page->protection ?? []);
        }
    }

    protected function parseImageInfo($data)
    {
        $this->sha1 = $data->sha1;
        $this->mime = $data->mime;
        $this->url = $data->url;
        $this->width = $data->width;
        $this->height = $data->height;
        $this->descriptionurl = $data->descriptionurl;
        $this->pagecount = isset($data->pagecount) ? $data->pagecount : 1;
    }

    protected function parseCategories($data)
    {
        $this->categories = array_map(function($x) {
            $value = explode(':', $x->title, 2);
            return $value[1];
        }, $data);
    }

    protected function parseProtection($data)
    {
        $this->protection = $data;
    }

    public function hasUploadProtection()
    {
        foreach ($this->protection as $restriction) {
            if (($restriction->type ?? null) === 'upload' && ($restriction->level ?? 'all') !== 'all') {
                return true;
            }
        }

        return false;
    }

    public function getShortSha1()
    {
        return substr($this->sha1, 0, 7);
    }

    public function getMediaInfoId()
    {
        return $this->pageid ? 'M' . $this->pageid : null;
    }
}
