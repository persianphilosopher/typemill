<?php

namespace Typemill\Controllers;

use Typemill\Models\Folder;
use Typemill\Models\WriteCache;
use Typemill\Models\WriteSitemap;
use Typemill\Models\WriteYaml;
use \Symfony\Component\Yaml\Yaml;
use Typemill\Models\VersionCheck;
use Typemill\Models\Helpers;
use Typemill\Events\OnPagetreeLoaded;
use Typemill\Events\OnBreadcrumbLoaded;
use Typemill\Events\OnItemLoaded;
use Typemill\Events\OnMarkdownLoaded;
use Typemill\Events\OnContentArrayLoaded;
use Typemill\Events\OnHtmlLoaded;
use Typemill\Extensions\ParsedownExtension;

class PageController extends Controller
{
	public function index($request, $response, $args)
	{
	
		/* Initiate Variables */
		$structure		= false;
		$contentHTML	= false;
		$item			= false;
		$breadcrumb 	= false;
		$description	= '';
		$settings		= $this->c->get('settings');
		$pathToContent	= $settings['rootPath'] . $settings['contentFolder'];
		$cache 			= new WriteCache();
		$uri 			= $request->getUri();
		$base_url		= $uri->getBaseUrl();

		try
		{
			/* if the cached structure is still valid, use it */
			if($cache->validate('cache', 'lastCache.txt',600))
			{
				$structure	= $this->getCachedStructure($cache);
				$cached 	= true;
			}
			else
			{
				/* if not, get a fresh structure of the content folder */
				$structure 	= $this->getFreshStructure($pathToContent, $cache, $uri);
				
				$cached		= false;

				/* if there is no structure at all, the content folder is probably empty */
				if(!$structure)
				{
					$content = '<h1>No Content</h1><p>Your content folder is empty.</p>'; 

					$this->render($response, '/index.twig', array( 'content' => $content ));
				}
				elseif(!$cache->validate('cache', 'lastSitemap.txt', 86400))
				{
					/* update sitemap */
					$sitemap = new WriteSitemap();
					$sitemap->updateSitemap('cache', 'sitemap.xml', 'lastSitemap.txt', $structure, $uri->getBaseUrl());

					/* check and update the typemill-version in the user settings */
					$this->updateVersion($uri->getBaseUrl());					
				}
			}
			
			/* dispatch event and let others manipulate the structure */
			$structure = $this->c->dispatcher->dispatch('onPagetreeLoaded', new OnPagetreeLoaded($structure))->getData();
		}
		catch (Exception $e)
		{
			echo $e->getMessage();
			exit(1);
		}
		
		/* if the user is on startpage */
		if(empty($args))
		{	
			/* check, if there is an index-file in the root of the content folder */
			$contentMD = file_exists($pathToContent . DIRECTORY_SEPARATOR . 'index.md') ? file_get_contents($pathToContent . DIRECTORY_SEPARATOR . 'index.md') : NULL;
		}
		else
		{
			/* get the request url */
			$urlRel = $uri->getBasePath() . '/' . $args['params'];			
			
			/* find the url in the content-item-tree and return the item-object for the file */
			$item = Folder::getItemForUrl($structure, $urlRel);
			
			/* if structure is cached and there is no item 
			if($cached && !$item)
			{
				/* get a fresh structure and search for the item again 
				$structure = $this->getFreshStructure($pathToContent, $cache, $uri); 
				$item = Folder::getItemForUrl($structure, $urlRel);
			}
			
			/* if there is still no item, return a 404-page */
			if(!$item)
			{
				return $this->render404($response, array( 'navigation' => $structure, 'settings' => $settings,  'base_url' => $base_url )); 
			}
			
			/* get breadcrumb for page */
			$breadcrumb = Folder::getBreadcrumb($structure, $item->keyPathArray);
			$breadcrumb = $this->c->dispatcher->dispatch('onBreadcrumbLoaded', new OnBreadcrumbLoaded($breadcrumb))->getData();
			
			/* add the paging to the item */
			$item = Folder::getPagingForItem($structure, $item);
			$item = $this->c->dispatcher->dispatch('onItemLoaded', new OnItemLoaded($item))->getData();
			
			/* check if url is a folder. If so, check if there is an index-file in that folder */
			if($item->elementType == 'folder' && $item->index)
			{
				$filePath = $pathToContent . $item->path . DIRECTORY_SEPARATOR . 'index.md';
			}
			elseif($item->elementType == 'file')
			{
				$filePath = $pathToContent . $item->path;
			}
			
			/* read the content of the file */
			$contentMD = isset($filePath) ? file_get_contents($filePath) : false;
		}
		
		$contentMD = $this->c->dispatcher->dispatch('onMarkdownLoaded', new OnMarkdownLoaded($contentMD))->getData();
		
		/* initialize parsedown */
		$parsedown 		= new ParsedownExtension();

		/* parse markdown-file to content-array */
		$contentArray 	= $parsedown->text($contentMD);
		$contentArray 	= $this->c->dispatcher->dispatch('onContentArrayLoaded', new OnContentArrayLoaded($contentArray))->getData();
	
		/* get the first image from content array */
		$firstImage		= $this->getFirstImage($contentArray);
		
		/* parse markdown-content-array to content-string */
		$contentHTML	= $parsedown->markup($contentArray);
		$contentHTML 	= $this->c->dispatcher->dispatch('onHtmlLoaded', new OnHtmlLoaded($contentHTML))->getData();
		
		/* create excerpt from content */
		$excerpt		= substr($contentHTML,0,320);
		$excerpt		= explode("</h1>", $excerpt);
		
		/* extract title from excerpt */
		$title			= isset($excerpt[0]) ? strip_tags($excerpt[0]) : $settings['title'];
		
		/* create description from excerpt */
		$description	= isset($excerpt[1]) ? strip_tags($excerpt[1]) : false;
		if($description)
		{
			$description 	= trim(preg_replace('/\s+/', ' ', $description));
			$lastSpace 		= strrpos($description, ' ');
			$description 	= substr($description, 0, $lastSpace);
		}
		
		/* get url and alt-tag for first image, if exists */
		if($firstImage)
		{	
			preg_match('#\((.*?)\)#', $firstImage, $img_url);
			if($img_url[1])
			{
				preg_match('#\[(.*?)\]#', $firstImage, $img_alt);
				
				$firstImage = array('img_url' => $base_url . $img_url[1], 'img_alt' => $img_alt[1]);
			}
		}
		
		/* 
			$timer['topiccontroller']=microtime(true);
			$timer['end topiccontroller']=microtime(true);
			Helpers::printTimer($timer);
		*/
		
		$route = empty($args) && $settings['startpage'] ? '/cover.twig' : '/index.twig';
		
		$this->render($response, $route, array('navigation' => $structure, 'content' => $contentHTML, 'item' => $item, 'breadcrumb' => $breadcrumb, 'settings' => $settings, 'title' => $title, 'description' => $description, 'base_url' => $base_url, 'image' => $firstImage ));
	}

	protected function getCachedStructure($cache)
	{
		return $cache->getCache('cache', 'structure.txt');
	}
	
	protected function getFreshStructure($pathToContent, $cache, $uri)
	{
		/* scan the content of the folder */
		$structure = Folder::scanFolder($pathToContent);

		/* if there is no content, render an empty page */
		if(count($structure) == 0)
		{
			return false;
		}

		/* create an array of object with the whole content of the folder */
		$structure = Folder::getFolderContentDetails($structure, $uri->getBaseUrl(), $uri->getBasePath());		

		/* cache navigation */
		$cache->updateCache('cache', 'structure.txt', 'lastCache.txt', $structure);
		
		return $structure;
	}
	
	protected function updateVersion($baseUrl)
	{
		/* check the latest public typemill version */
		$version 		= new VersionCheck();
		$latestVersion 	= $version->checkVersion($baseUrl);

		if($latestVersion)
		{
			/* check, if user-settings exist */
			$yaml 			= new WriteYaml();
			$userSettings 	= $yaml->getYaml('settings', 'settings.yaml');
			if($userSettings)
			{
				/* if there is no version info in the settings or if the version info is outdated */
				if(!isset($userSettings['latestVersion']) || $userSettings['latestVersion'] != $latestVersion)
				{
					/* write the latest version into the user-settings */
					$userSettings['latestVersion'] = $latestVersion;
					$yaml->updateYaml('settings', 'settings.yaml', $userSettings);									
				}
			}
		}
	}
	
	protected function getFirstImage(array $contentBlocks)
	{
		foreach($contentBlocks as $block)
		{
			if($block['element']['name'] == 'p' && substr($block['element']['text'], 0, 2) == '![' )
			{
				return $block['element']['text'];
			}
		}
		
		return false;
	}
}