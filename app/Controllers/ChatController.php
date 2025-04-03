<?php

namespace App\Controllers;

use App\Factories\HandlerFactory;
use App\Models\CustomerModel;
use App\Models\MessageModel;
use App\Models\MessageRoomModel;
use App\Models\UserSocialModel;
use App\Services\MessageService;
use CodeIgniter\HTTP\ResponseInterface;

class ChatController extends BaseController
{
    private MessageService $messageService;
    private CustomerModel $customerModel;
    private MessageModel $messageModel;
    private MessageRoomModel $messageRoomModel;
    private UserSocialModel $userSocialModel;

    public function __construct()
    {
        $this->messageService = new MessageService();
        $this->customerModel = new CustomerModel();
        $this->messageModel = new MessageModel();
        $this->messageRoomModel = new MessageRoomModel();
        $this->userSocialModel = new UserSocialModel();
    }

    /**
     * ฟังก์ชันแสดงหน้าหลักของระบบ Chat
     * - โหลดข้อมูลห้องสนทนา
     * - เตรียมข้อมูลสำหรับ View
     */
    public function index()
    {
        // echo(hashidsEncrypt(10)); exit();
        // exit;
        // TODO:: HANDLE
        // NOTE:: ต้องจัดการ ID, Refactor foreach

        // Mock userID สำหรับ Session (สมมติว่าผู้ใช้ ID 1 กำลังล็อกอิน)
        // session()->set(['userID' => 1]);
        $userID = hashidsDecrypt(session()->get('userID'));

        // ดึงรายการห้องสนทนา
        $rooms = $this->messageRoomModel->getMessageRoomByUserID($userID);

        // เตรียมข้อมูลเพิ่มเติมให้แต่ละห้อง
        foreach ($rooms as $room) {
            // ไอคอนแพลตฟอร์ม
            $room->ic_platform = $this->getPlatformIcon($room->platform);

            // ชื่อลูกค้า
            $customer = $this->customerModel->getCustomerByID($room->customer_id);
            $room->customer_name = $customer->name;
            $room->profile = $customer->profile;

            // ข้อความล่าสุด
            $lastMessage = $this->messageModel->getLastMessageByRoomID($room->id);

            $prefix = '';
            if ($lastMessage->send_by == 'Admin') $prefix = 'คุณ: ';
            $room->last_message = $lastMessage->message ?  $prefix . $lastMessage->message : '';
            $room->message_type = $lastMessage->message ?  $lastMessage->message_type : '';
            $room->last_time = $lastMessage->created_at ?? '';
        }

        // ส่งข้อมูลไปยัง View
        return view('/app', [
            'content' => 'chat/index', // ชื่อไฟล์ View
            'title' => 'Chat', // ชื่อหน้า
            'js_critical' => '
                <script src="https://code.jquery.com/jquery-3.7.1.js" crossorigin="anonymous"></script>    
                <script src="app/chat.js"></script>
            ', // ไฟล์ JS
            'rooms' => $rooms // ข้อมูลห้องสนทนา
        ]);
    }

    /**
     * ฟังก์ชันสำหรับดึงข้อความในห้องสนทนาตาม roomID
     * - คืนค่าข้อมูลในรูปแบบ JSON
     */
    public function fetchMessages($roomID)
    {
        $room = $this->messageRoomModel->getMessageRoomByID($roomID);
        $userSocial = $this->userSocialModel->getUserSocialByID($room->user_social_id);
        $customer = $this->customerModel->getCustomerByID($room->customer_id);
        $messages = $this->messageModel->getMessageRoomByRoomID($roomID);
        $data = [
            'room' => $room,
            'customer' => $customer,
            'messages' => $messages,
            'userSocial' => $userSocial
        ];

        return $this->response->setJSON(json_encode($data));
    }

    // -----------------------------------------------------------------------------
    // ส่วนจัดการ การส่งข้อความ
    // -----------------------------------------------------------------------------

    /**
     * ฟังก์ชันสำหรับส่งข้อความจากฝั่ง Admin
     * - บันทึกข้อความในฐานข้อมูล
     * - ส่งข้อความไปยัง WebSocket Server
     * - คืนค่า JSON Response
     */
    public function sendMessage()
    {
        // $input = $this->request->getJSON();
        $userID = hashidsDecrypt(session()->get('userID'));
        $message = $this->request->getPost('message');
        $file_img = $this->request->getFile('file_IMG');
        $room_id = $this->request->getPost('room_id');
        $platform = $this->request->getPost('platform');
        $message_type = "text";


        if ($message == "") {
            $message_type = 'image';
            $fileName =  $userID . '_' . $file_img->getRandomName();
            $file_img->move('uploads', $fileName);
            $file_Path = 'uploads/' . $fileName;
            $fileContent = fopen($file_Path, 'r');
            // อัปโหลดไปยัง Spaces
            $message = uploadToSpaces($fileContent, $fileName, $message_type, $platform);

            if ($message != "") {
                unlink('uploads/' . $fileName);
            }
        }


        $input = [
            'message' => $message,
            'message_type' => $message_type,
            'room_id' => $room_id,
            'platform' => $platform
        ];

        $messageRoomModel = $this->messageRoomModel->getMessageRoomByID($room_id);
        $userSocial = $this->userSocialModel->getUserSocialByID($messageRoomModel->user_social_id);
        $customer = $this->customerModel->getCustomerByID($messageRoomModel->customer_id);

        try {

            $handler = HandlerFactory::createHandler($platform, $this->messageService);

            $handler->handleReplyByManual($input, $customer);

            return $this->response->setJSON(['status' => 'success']);
        } catch (\InvalidArgumentException $e) {
            log_message('error', "ChatController error: " . $e->getMessage());
            return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)->setJSON(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // -----------------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------------
    private function getPlatformIcon(string $platform): string
    {
        return match ($platform) {
            'Facebook' => 'ic-Facebook.png',
            'Line' => 'ic-Line.png',
            'WhatsApp' => 'ic-WhatsApp.png',
            'Instagram' => 'ic-Instagram.svg',
            'Tiktok' => 'ic-Tiktok.png',
            default => '',
        };
    }
}
