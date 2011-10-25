<?php

class BlocksHtml extends CHtml
{
	public static function resource($resourcePath, $pluginHandle = null)
	{
		$resourceString = BLOCKS_RESOURCEPROCESSOR_URL.'?resourcePath='.self::encode($resourcePath);

		if($pluginHandle != null)
		{
			$resourceString .= '&pluginHandle='.self::encode($pluginHandle);
		}

		return $resourceString;
	}


	public static function unixTimeToPrettyDate($unixTime)
	{
		return Blocks::app()->dateFormatter->format('MM-dd-yyyy HH:mm:ss', $unixTime);
	}
}
