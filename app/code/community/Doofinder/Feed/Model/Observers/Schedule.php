<?php

class Doofinder_Feed_Model_Observers_Schedule
{
    /**
     * Register missing / reset schedules after configuration saves.
     *
     * @param Varien_Event_Observer $observer
     */
    public function saveNewSchedule($observer)
    {
        // Get store code
        $currentStoreCode = $observer->getStore();

        // Stores array holding all store codes
        $codes = array();

        // Create stores codes array
        if ($currentStoreCode) {
            $codes[] = $currentStoreCode;
        } else {
            $stores = Mage::app()->getStores();
            foreach ($stores as $store) {
                if ($store->getIsActive()) {
                    $codes[] = $store->getCode();
                }
            }
        }

        // Check if user wants to reset the schedule
        $reset = (bool) Mage::app()->getRequest()->getParam('reset');

        foreach ($codes as $storeCode) {
            $this->_updateProcess($storeCode, $reset);
        }
    }

    /**
     * Regenerate finished shcedules.
     *
     * @param Varien_Event_Observer $observer
     */
    public function regenerateSchedule()
    {
        // Get store
        $stores = Mage::app()->getStores();

        foreach ($stores as $store) {
            if ($store->getIsActive()) {
                $this->_updateProcess($store->getCode());
            }
        }
    }

    /**
     * Gets process for given store code
     *
     * @param string $storeCode
     * @return Doofinder_Feed_Model_Cron
     */
    private function _getProcessByStoreCode($storeCode = 'default')
    {
        $process = Mage::getModel('doofinder_feed/cron')->load($storeCode, 'store_code');
        return $process->getId() ? $process : null;
    }

    /**
     * Checks if process is registered in doofinder cron table
     *
     * @param string $storeCode
     * @return bool
     */
    private function _isProcessRegistered($storeCode = 'default')
    {
        $process = $this->_getProcessByStoreCode($storeCode);
        return $process ? true : false;
    }

    /**
     * Update process for given store code.
     * If process does not exits - create it.
     * Reschedule the process if it needs it.
     *
     * @param Doofinder_Feed_Model_Cron $process
     * @param boolean $reset
     */
    private function _updateProcess($storeCode = 'default', $reset = false)
    {
        // Get store
        $helper = Mage::helper('doofinder_feed');
        $config = $helper->getStoreConfig($storeCode);

        $isEnabled = (bool) $config['enabled'];

        // Try loading store process
        $process = $this->_getProcessByStoreCode($storeCode);

        // Create new process if it not exists
        if (!$process) {
            $process = $this->_registerProcess($storeCode);
        }

        // Enable/disable process if it needs to
        if ($isEnabled) {
            if ($process->getStatus() == $helper::STATUS_DISABLED) {
                $this->_enableProcess($process);
            }
        } else {
            if ($process->getStatus() != $helper::STATUS_DISABLED) {
                $this->_removeTmpXml($storeCode);
                $this->_disableProcess($process);
                return $this;
            }
        }

        // Do not process the schedule if it has insufficient file permissions
        if (!$this->_checkFeedFilePermission($storeCode)) {
            Mage::getSingleton('adminhtml/session')->addError($helper->__('Insufficient file permissions for store: %s. Check if the feed file is writeable', $store->getName()));
            return $this;
        }

        // Reschedule the process if it needs to
        if ($reset || $process->getStatus() == $helper::STATUS_WAITING) {
            $this->_removeTmpXml($storeCode);
            $this->_rescheduleProcess($config, $process);
        }
    }

    /**
     * Register a new process
     *
     * @return Doofinder_Feed_Model_Cron
     */
    private function _registerProcess($storeCode = 'default')
    {
        $helper = Mage::helper('doofinder_feed');
        $config = $helper->getStoreConfig($storeCode);
        if (empty($status)) {
            $status = $config['enabled'] ? $helper::STATUS_WAITING : $helper::STATUS_DISABLED;
        }

        $data = array(
            'store_code'    =>  $storeCode,
            'status'        =>  $status,
            'message'       =>  $helper::MSG_EMPTY,
            'complete'      =>  '-',
            'next_run'      =>  '-',
            'next_iteration'=>  '-',
            'last_feed_name'=>  'None',
        );
        $process = Mage::getModel('doofinder_feed/cron')->setData($data)->save();

        Mage::helper('doofinder_feed/log')->log($process, Doofinder_Feed_Helper_Log::STATUS, $helper->__('Process has been registered'));

        return $process;
    }

    /**
     * Enable the process
     *
     * @param Doofinder_Feed_Model_Cron $process
     */
    private function _enableProcess(Doofinder_Feed_Model_Cron $process)
    {
        $helper = Mage::helper('doofinder_feed');
        $process->setStatus($helper::STATUS_WAITING)->save();
        Mage::helper('doofinder_feed/log')->log($process, Doofinder_Feed_Helper_Log::STATUS, $helper->__('Process has been disabled'));
    }

    /**
     * Disable the process
     *
     * @param Doofinder_Feed_Model_Cron $process
     */
    private function _disableProcess(Doofinder_Feed_Model_Cron $process)
    {
        $helper = Mage::helper('doofinder_feed');
        $process->setStatus($helper::STATUS_DISABLED)->save();
        Mage::helper('doofinder_feed/log')->log($process, Doofinder_Feed_Helper_Log::STATUS, $helper->__('Process has been enabled'));
    }

    /**
     * Remove tmp xml file.
     *
     * @param string $store_code
     * @return bool
     */
    private function _removeTmpXml($store_code = null)
    {
        if (empty($store_code)) {
            return false;
        }
        $helper = Mage::helper('doofinder_feed');
        $config = $helper->getStoreConfig($store_code);
        $filePath = Mage::getBaseDir('media').DS.'doofinder'.DS.$config['xmlName'].'.tmp';
        if (file_exists($filePath)) {
            $success = unlink($filePath);
            if ($success) {
                Mage::getSingleton('core/session')->addSuccess("Temporary xml file: {$filePath} has beed removed.");
                return true;
            } else {
                Mage::getSingleton('core/session')->addError("Could not remove {$filePath}; This can lead to some errors. Remove this file manually.");
                return false;
            }
        }

        return false;
    }

    /**
     * Validate file permissions for feed generation.
     *
     * @return boolean
     */
    protected function _checkFeedFilePermission($storeCode)
    {
        $helper = Mage::helper('doofinder_feed');

        try {
            $helper->createFeedDirectory();
        } catch (Exception $e) {
            return false;
        }

        $dir = $helper->getFeedDirectory();
        $path = $helper->getFeedPath($storeCode);
        $tmpPath = $helper->getFeedTemporaryPath($storeCode);

        return is_writeable($dir) && (!file_exists($path) || is_writeable($path)) && (!file_exists($tmpPath) || is_writeable($tmpPath));
    }

    /**
     * Reschedule the process accordingly to process configuration.
     *
     * @param array $storeConfig
     * @param Doofinder_Feed_Model_Cron $process
     */
    protected function _rescheduleProcess($config, Doofinder_Feed_Model_Cron $process)
    {
        $helper = Mage::helper('doofinder_feed');

        $timecreated   = strftime("%Y-%m-%d %H:%M:%S",  mktime(date("H"), date("i"), date("s"), date("m"), date("d"), date("Y")));
        $timescheduled = $helper->getScheduledAt($config['time'], $config['frequency']);
        $jobCode = $helper::JOB_CODE;

        $process->setStatus($helper::STATUS_PENDING)
            ->setComplete('0%')
            ->setNextRun($timescheduled)
            ->setNextIteration($timescheduled)
            ->setOffset(0)
            ->setMessage($helper::MSG_PENDING)
            ->setErrorStack(0)
            ->save();

        Mage::helper('doofinder_feed/log')->log($process, Doofinder_Feed_Helper_Log::STATUS, $helper->__('Process has been scheduled'));
    }
}