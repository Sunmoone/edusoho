<?php

namespace Biz\Course\Service\Impl;

use Biz\BaseService;
use Biz\Course\Service\CourseOrderService;
use Biz\Course\Service\CourseSetService;
use Biz\Course\Service\MemberService;
use AppBundle\Common\ArrayToolkit;
use AppBundle\Common\StringToolkit;
use Topxia\Service\Common\ServiceKernel;

class CourseOrderServiceImpl extends BaseService implements CourseOrderService
{
    public function cancelOrder($id)
    {
        $this->getOrderService()->cancelOrder($id);
    }

    public function createOrder($info)
    {
        $connection = ServiceKernel::instance()->getConnection();
        try {
            $connection->beginTransaction();

            $user = $this->getCurrentUser();
            if (!$user->isLogin()) {
                throw $this->createServiceException($this->getKernel()->trans('用户未登录，不能创建订单'));
            }

            if (!ArrayToolkit::requireds($info, array('targetId', 'payment'))) {
                throw $this->createServiceException($this->getKernel()->trans('订单数据缺失，创建订单失败。'));
            }

            // 获得锁
            $user = $this->getUserService()->getUser($user['id'], true);

            if ($this->getCourseMemberService()->isCourseStudent($info['targetId'], $user['id'])) {
                throw $this->createServiceException($this->getKernel()->trans('已经是该教学计划学员，操作失败。'));
            }

            $course = $this->getCourseService()->getCourse($info['targetId']);
            if (empty($course)) {
                throw $this->createServiceException($this->getKernel()->trans('教学计划不存在，操作失败。'));
            }

            if ($course['buyExpiryTime'] && $course['buyExpiryTime'] < time()) {
                throw $this->createServiceException($this->getKernel()->trans('该教学计划已经超过购买截止日期，不允许购买'));
            }

            if ($course['approval'] && $user['approvalStatus'] != 'approved') {
                throw $this->createServiceException($this->getKernel()->trans('该教学计划需要实名认证，你还没有实名认证，不可购买。'));
            }

            $courseSet = $this->getCourseSetService()->getCourseSet($course['courseSetId']);

            $order = array();

            $order['userId'] = $user['id'];
            $order['title'] = $this->getKernel()->trans('购买课程《%courseSetTitle%》- %courseTitle%', array('%courseSetTitle%' => $courseSet['title'], '%courseTitle%' => $course['title']));
            $order['targetType'] = 'course';
            $order['targetId'] = $course['id'];
            if (!empty($course['discountId'])) {
                $order['discountId'] = $course['discountId'];
                $order['discount'] = $course['discount'];
            }

            $order['payment'] = $info['payment'];
            $order['amount'] = empty($info['amount']) ? 0 : $info['amount'];
            $order['priceType'] = $info['priceType'];
            $order['totalPrice'] = $info['totalPrice'];
            $order['coinRate'] = $info['coinRate'];
            $order['coinAmount'] = $info['coinAmount'];

            $courseSetting = $this->getSettingService()->get('course', array());

            if (array_key_exists('coursesPrice', $courseSetting)) {
                $notShowPrice = $courseSetting['coursesPrice'];
            } else {
                $notShowPrice = 0;
            }

            if ($notShowPrice == 1) {
                $order['amount'] = 0;
                $order['totalPrice'] = 0;
            }

            $order['snPrefix'] = 'C';

            if (!empty($info['coupon'])) {
                $order['coupon'] = $info['coupon'];
                $order['couponDiscount'] = $info['couponDiscount'];
            }

            if (!empty($info['note'])) {
                $order['data'] = array('note' => $info['note']);
            }
            $order = $this->getOrderService()->createOrder($order);
            if (empty($order)) {
                throw $this->createServiceException($this->getKernel()->trans('创建订单失败！'));
            }
            // 免费课程或VIP用户，就直接将订单置为已购买
            if ((intval($order['amount'] * 100) == 0 && intval($order['coinAmount'] * 100) == 0 && empty($order['coupon'])) || !empty($info['becomeUseMember'])) {
                list($success, $order) = $this->getOrderService()->payOrder(array(
                    'sn' => $order['sn'],
                    'status' => 'success',
                    'amount' => $order['amount'],
                    'paidTime' => time(),
                ));

                $info = array(
                    'orderId' => $order['id'],
                    'remark' => empty($order['data']['note']) ? '' : $order['data']['note'],
                );
                $this->getCourseMemberService()->becomeStudent($order['targetId'], $order['userId'], $info);
            }

            $connection->commit();

            return $order;
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    public function doSuccessPayOrder($id)
    {
        $order = $this->getOrderService()->getOrder($id);
        if (empty($order) || $order['targetType'] != 'course') {
            throw $this->createServiceException($this->getKernel()->trans('非教学计划订单，加入教学计划失败。'));
        }

        $info = array(
            'orderId' => $order['id'],
            'remark' => empty($order['data']['note']) ? '' : $order['data']['note'],
        );

        if (!$this->getCourseMemberService()->isCourseStudent($order['targetId'], $order['userId'])) {
            $this->getCourseMemberService()->becomeStudent($order['targetId'], $order['userId'], $info);
        } else {
            $this->getOrderService()->createOrderLog($order['id'], 'pay_success', $this->getKernel()->trans('当前用户已经是教学计划学员，支付宝支付成功。'), $order);
            $this->getLogService()->warning('course_order', 'pay_success', $this->getKernel()->trans('当前用户已经是教学计划学员，支付成功。'), $order);
        }

        return;
    }

    public function applyRefundOrder($id, $amount, $reason, $container)
    {
        $user = $this->getCurrentUser();
        $order = $this->getOrderService()->getOrder($id);
        if (empty($order)) {
            throw $this->createServiceException($this->getKernel()->trans('订单不存在，不能申请退款。'));
        }

        $refund = $this->getOrderService()->applyRefundOrder($id, $amount, $reason);

        if ($refund['status'] == 'created') {
            $this->getCourseMemberService()->lockStudent($order['targetId'], $order['userId']);

            $setting = $this->getSettingService()->get('refund');
            $message = (empty($setting) || empty($setting['applyNotification'])) ? '' : $setting['applyNotification'];
            $course = $this->getCourseService()->getCourse($order['targetId']);
            $courseUrl = $container->get('router')->generate('course_show', array('id' => $course['id']));
            if ($message) {
                $variables = array(
                    'item' => "<a href='{$courseUrl}'>{$course['title']}</a>",
                );
                $message = StringToolkit::template($message, $variables);
                $this->getNotificationService()->notify($refund['userId'], 'default', $message);
            }

            $adminmessage = $this->getKernel()->trans('用户%nickname%申请退款<a href="%courseUrl%">%title%</a>教学计划，请审核。', array('%nickname%' => $user['nickname'], '%courseUrl%' => $courseUrl, '%title%' => $course['title']));
            $adminCount = $this->getUserService()->countUsers(array('roles' => 'ADMIN'));
            $admins = $this->getUserService()->searchUsers(array('roles' => 'ADMIN'), array('id' => 'DESC'), 0, $adminCount);
            foreach ($admins as $key => $admin) {
                $this->getNotificationService()->notify($admin['id'], 'default', $adminmessage);
            }
        } elseif ($refund['status'] == 'success') {
            $this->getCourseMemberService()->removeStudent($order['targetId'], $order['userId']);
        }

        return $refund;
    }

    public function cancelRefundOrder($id)
    {
        $order = $this->getOrderService()->getOrder($id);
        if (empty($order) || $order['targetType'] != 'course') {
            throw $this->createServiceException($this->getKernel()->trans('订单不存在，取消退款申请失败。'));
        }

        $this->getOrderService()->cancelRefundOrder($id);
        if ($this->getCourseMemberService()->isCourseStudent($order['targetId'], $order['userId'])) {
            $this->getCourseMemberService()->unlockStudent($order['targetId'], $order['userId']);
        }
    }

    public function updateOrder($id, $orderFileds)
    {
        $orderFileds = array(
            'priceType' => $orderFileds['priceType'],
            'totalPrice' => $orderFileds['totalPrice'],
            'amount' => $orderFileds['amount'],
            'coinRate' => $orderFileds['coinRate'],
            'coinAmount' => $orderFileds['coinAmount'],
            'userId' => $orderFileds['userId'],
            'payment' => $orderFileds['payment'],
            'targetId' => $orderFileds['courseId'],
        );

        return $this->getOrderService()->updateOrder($id, $orderFileds);
    }

    protected function getCashService()
    {
        return $this->createService('Cash:CashService');
    }

    protected function getCashAccountService()
    {
        return $this->createService('Cash:CashAccountService');
    }

    protected function getUserService()
    {
        return $this->createService('User:UserService');
    }

    protected function getLogService()
    {
        return $this->createService('System:LogService');
    }

    protected function getCourseService()
    {
        return $this->createService('Course:CourseService');
    }

    /**
     * @return CourseSetService
     */
    protected function getCourseSetService()
    {
        return $this->createService('Course:CourseSetService');
    }

    protected function getOrderService()
    {
        return $this->createService('Order:OrderService');
    }

    protected function getNotificationService()
    {
        return $this->createService('User:NotificationService');
    }

    protected function getSettingService()
    {
        return $this->createService('System:SettingService');
    }

    /**
     * @return MemberService
     */
    protected function getCourseMemberService()
    {
        return $this->createService('Course:MemberService');
    }

    protected function getKernel()
    {
        return ServiceKernel::instance();
    }
}
