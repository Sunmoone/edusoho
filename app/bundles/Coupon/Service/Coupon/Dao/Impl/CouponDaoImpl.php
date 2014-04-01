<?php

namespace Coupon\Service\Coupon\Dao\Impl;

use Topxia\Service\Common\BaseDao;
use Coupon\Service\Coupon\Dao\CouponDao;
use PDO;

class CouponDaoImpl extends BaseDao implements CouponDao
{
    protected $table = 'coupon';

    public function getCoupon($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";

        return $this->getConnection()->fetchAssoc($sql, array($id)) ? : null;
    }

    public function getCouponByCode($code)
    {
        $sql = "SELECT * FROM {$this->table} WHERE code = ? LIMIT 1";

        return $this->getConnection()->fetchAssoc($sql, array($code)) ? : null;
    }

    public function updateCoupon($id, $fields)
    {
        $this->getConnection()->update($this->table, $fields, array('id' => $id));
        return $this->getCoupon($id);
    }

    public function findCouponsByBatchId($batchId, $start, $limit)
    {
        $this->filterStartLimit($start, $limit);
        $sql = "SELECT * FROM  $this->table WHERE batchId = {$batchId} ORDER BY createdTime DESC LIMIT {$start} , {$limit}";

        return $this->getConnection()->fetchAll($sql);
    }

    public function searchCoupons($conditions, $orderBy, $start, $limit)
    {
        $this->filterStartLimit($start, $limit);
        $builder = $this->_createSearchQueryBuilder($conditions)
            ->select('*')
            ->orderBy($orderBy[0], $orderBy[1])
            ->setFirstResult($start)
            ->setMaxResults($limit);
        return $builder->execute()->fetchAll() ? : array(); 
    }

    public function searchCouponsCount(array $conditions)
    {
        $builder = $this->_createSearchQueryBuilder($conditions)
            ->select('COUNT(id)');
        return $builder->execute()->fetchColumn(0);
    }
    
    public function addCoupon($coupon)
    {
        $affected = $this->getConnection()->insert($this->table, $coupon);
        if ($affected <= 0) {
            throw $this->createDaoException('Insert Coupon error.');
        }

        return $this->getCoupon($this->getConnection()->lastInsertId());
    }

    public function deleteCouponsByBatch($id)
    {
        return $this->getConnection()->delete($this->table, array('batchId' => $id));
    }

    private function _createSearchQueryBuilder($conditions)
    {   
        $conditions = array_filter($conditions);
        if (isset($conditions['code'])) 
        {
            $conditions['codeLike'] = "%{$conditions['code']}%";
            unset($conditions['code']);
        }
        if (isset($conditions['startDateTime'])) 
        {   
            $conditions['startDateTime'] = strtotime($conditions['startDateTime']."\n00:00:00");
        }
        if (isset($conditions['endDateTime']))
        {
            $conditions['endDateTime'] = strtotime($conditions['endDateTime']."+1 day");
        }
        $builder = $this->createDynamicQueryBuilder($conditions)
            ->from($this->table, 'coupon')
            ->andWhere('targetId = :targetId')
            ->andWhere('targetType = :targetType')
            ->andWhere('batchId = :batchId')
            ->andWhere('type = :type')
            ->andWhere('status = :status')
            ->andWhere('createdTime >= :startDateTime')
            ->andWhere('createdTime < :endDateTime')
            ->andWhere('code LIKE :codeLike');

        return $builder;
    }

}