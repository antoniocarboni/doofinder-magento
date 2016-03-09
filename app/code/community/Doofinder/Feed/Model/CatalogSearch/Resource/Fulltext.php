<?php

class Doofinder_Feed_Model_CatalogSearch_Resource_Fulltext extends Mage_CatalogSearch_Model_Resource_Fulltext
{
    /**
     * Get stored results in CatalogSearch cache
     *
     * @param int $query_id
     * @param int $limit
     * @return array
     */
    protected function getStoredResults($query_id, $limit)
    {
        $adapter = $this->_getReadAdapter();

        $select = $adapter->select()
            ->from($this->getTable('catalogsearch/result'), 'product_id')
            ->where('query_id = ?', $query_id)
            ->limit($limit)
            ->order('relevance desc');

        $results = array();
        foreach ($adapter->fetchAll($select) as $result) {
            $results[] = $result['product_id'];
        }

        return $results;
    }

    /**
     * Override prepareResult.
     *
     * @param Mage_CatalogSearch_Model_Fulltext $object
     * @param string $queryText
     * @param Mage_CatalogSearch_Model_Query $query
     *
     * @return Doofinder_Feed_Model_CatalogSearch_Resource_Fulltext
     */
    public function prepareResult($object, $queryText, $query)
    {
        if(!Mage::getStoreConfigFlag('doofinder_search/internal_settings/enable', Mage::app()->getStore())) {
            return parent::prepareResult($object, $queryText, $query);
        }

        $helper = Mage::helper('doofinder_feed/search');

        // Fetch initial results
        $results = $helper->performDoofinderSearch($queryText);

        $adapter = $this->_getWriteAdapter();

        if ($query->getIsProcessed()) {
            $storedResults = $this->getStoredResults($query->getId(), count($results));

            // Compare results checksum
            if ($this->calculateChecksum($results) == $this->calculateChecksum($storedResults)) {
                return $this;
            }

            // Delete results
            $select = $adapter->select()
                ->from($this->getTable('catalogsearch/result'), 'product_id')
                ->where('query_id = ?', $query->getId());

            $adapter->query($adapter->deleteFromSelect($select, $this->getTable('catalogsearch/result')));
        }

        try {

            // Fetch all results
            $results = $helper->getAllResults();

            if (!empty($results)) {
                $data = array();
                $relevance = count($results);
                foreach($results as $product_id) {
                    $data[] = array(
                        'query_id'   => $query->getId(),
                        'product_id' => $product_id,
                        'relevance'  => $relevance--,
                    );
                }

                $adapter->insertMultiple($this->getTable('catalogsearch/result'), $data);
            }

            $query->setIsProcessed(1);

        } catch (Exception $e) {
            Mage::logException($e);
            return parent::prepareResult($object, $queryText, $query);
        }

        return $this;
    }

    /**
     * Calculate results checksum
     *
     * @param array[int] $results
     * @return string
     */
    protected function calculateChecksum(array $results)
    {
        return hash('sha256', implode(',', $results));
    }
}
