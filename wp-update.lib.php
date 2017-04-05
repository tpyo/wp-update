<?php
/**
* Copyright (c) 2016, Donovan Schönknecht.  All rights reserved.
*
* Redistribution and use in source and binary forms, with or without
* modification, are permitted provided that the following conditions are met:
*
* - Redistributions of source code must retain the above copyright notice,
*   this list of conditions and the following disclaimer.
* - Redistributions in binary form must reproduce the above copyright
*   notice, this list of conditions and the following disclaimer in the
*   documentation and/or other materials provided with the distribution.
*
* THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
* AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
* IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
* ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
* LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
* CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
* SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
* INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
* CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
* ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
* POSSIBILITY OF SUCH DAMAGE.
*/

class WPUpdate
{
        protected $_folder, $_latest, $_pluginInfo = array(), $_tempCleanup = array();

        function __construct($folder = null)
        {
                if ($folder !== null) $this->setFolder($folder);
                $this->_getLatestVersion();
        }

        public function setFolder($folder)
        {
                $folder = rtrim($folder, '/');
                if (!file_exists($folder) || !is_dir($folder) || !file_exists($folder . '/wp-config.php'))
                        throw new Exception('Invalid WP installation folder: ' . $folder);
                $this->_folder = $folder;
        }

        protected function _getLatestVersion()
        {
                echo "* Getting version information..." . PHP_EOL;

                if (($version = @file_get_contents('http://api.wordpress.org/core/version-check/1.6/')) !== false
                && ($version = @unserialize($version)) !== false)
                {
                        if (is_array($version) && isset($version['offers']))
                                foreach ($version['offers'] as $offer)
                                        if ($offer['response'] == 'upgrade')
                                        {
                                                $this->_latest = array(
                                                        'current' => $offer['current'],
                                                        'packages' => $offer['packages']
                                                );
                                                return;
                                        }
                }
                throw new Exception('Unable to retrieve version info from wordpress.org');
        }

        protected function _download($package, $outFile)
        {
                if (($data = file_get_contents($package)) !== false)
                {
                        $this->_tempCleanup[] = $outFile;
                        return (file_put_contents($outFile, $data) > 0);
                }
                return false;
        }

        public function update()
        {
                $tmpFile = sprintf('/tmp/.wp-update_%s.zip', $this->_latest['current']);

                if (!file_exists($tmpFile))
                {
                        echo "* Downloading Wordpress " . $this->_latest['current'] . "..." . PHP_EOL;

                        if ($this->_download($this->_latest['packages']['no_content'], $tmpFile))
                        {
                                echo "* Modifying ZIP archive..." . PHP_EOL;

                                $zip = new ZipArchive;
                                if ($zip->open($tmpFile) === true)
                                {
                                        for ($i = 0; $i < $zip->numFiles; $i++)
                                        {
                                                $stat = $zip->statIndex($i);
                                                if ($stat['name'] !== 'wordpress/' &&
                                                substr($stat['name'], 0, 10) == 'wordpress/')
                                                        $zip->renameName($stat['name'], substr($stat['name'], 10));
                                        }
                                        $zip->deleteName('wordpress/');
                                        $zip->close();
                                }
                        }
                        else
                                echo "* ERROR: Unable to download from wordpress.org"  . PHP_EOL;
                }

                if (file_exists($tmpFile))
                {
                        echo "* Updating Wordpress files in {$this->_folder}..." . PHP_EOL;
                        $zip = new ZipArchive;
                        if ($zip->open($tmpFile) === true)
                        {
                                $zip->open($tmpFile);
                                $zip->extractTo($this->_folder . '/');
                                $zip->close();

                                return true;
                        }
                        else
                                echo "* ERROR: Failed to extract ZIP archive" . PHP_EOL;
                }

                //unlink($tmpFile);
                return false;
        }

        public function updatePlugins($skip = array())
        {
                $skip = array_merge($skip, array('index.php', 'hello.php'));
                $path = $this->_folder . '/wp-content/plugins';

                $plugins = array();

                foreach (new DirectoryIterator($path) as $file)
                {
                        if ($file->isDot() || in_array($file->getFilename(), $skip)) continue;

                        if ($file->isDir())
                        {
                                if (file_exists(sprintf('%s/%s/%s.php',
                                $path, $file->getFilename(), $file->getFilename())))
                                        $plugins[] = $file->getFilename();
                        }
                        elseif ($file->isFile())
                        {
                                if ($file->getExtension() == 'php')
                                        $plugins[] = str_replace('.php', '', $file->getFilename());
                        }
                }

                foreach ($plugins as $plugin)
                {
                        if (($info = $this->_getPluginInfo($plugin)) !== false)
                        {
                                $tmpFile = sprintf('/tmp/.wp-plugin_%s.zip', $plugin);
                                if (file_exists($tmpFile) || $this->_download($info->download_link, $tmpFile))
                                {
                                        echo "* Updating plugin files for {$plugin}..." . PHP_EOL;
                                        $zip = new ZipArchive;
                                        if ($zip->open($tmpFile) === true)
                                        {
                                                $zip->open($tmpFile);
                                                $zip->extractTo($path);
                                                $zip->close();
                                        }
                                }
                        }
                        else
                        {
                                echo "* Possible invalid plugin: {$plugin} - please add to skip list to avoid invalid API calls!" . PHP_EOL;
                        }
                }
        }

        protected function _getPluginInfo($name)
        {
                // Some API info:
                // http://dd32.id.au/projects/wordpressorg-plugin-information-api-docs/

                if (isset($this->_pluginInfo[$name])) return $this->_pluginInfo[$name];

                $fields = array(
                        'action' => 'plugin_information',
                        'request' => serialize(
                                (object)array(
                                        'slug' => $name
                                )
                        )
                );

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'http://api.wordpress.org/plugins/info/1.0/');
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, sizeof($fields));
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields, '=', '&'));

                if (($result = curl_exec($ch)) !== false)
                {
                        curl_close($ch);
                        $data = @unserialize($result);
                        if (is_object($data))
                        {
                                $this->_pluginInfo[$name] = $data;
                                return $this->_pluginInfo[$name];
                        }
                }
                @curl_close($ch);
                return false;
        }

        public function cleanup()
        {
                foreach ($this->_tempCleanup as $file) unlink($file);
                echo "* Complete" . PHP_EOL;
        }

}
