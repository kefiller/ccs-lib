<?php

namespace CCS;

abstract class IApiClient
{

    /** @var string */
    protected $url;

    /** @var string */
    protected $authToken;

    /** @var array */
    protected $req;

    public function __construct(string $url, string $authToken)
    {
        //if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
        if (!$url) {
            throw new \Exception("Empty url");
        }
        if (!$authToken) {
            throw new \Exception("Empty authToken");
        }
        $this->url = $url;
        $this->authToken = $authToken;
        $this->req['auth'] = $authToken;
    }

    /** @return Result */
    public function systemPing()
    {
        $this->req['method'] = 'system.ping';
        unset($this->req['params']);

        $ret = $this->request();
        if ($ret->error()) {
            return $ret;
        }

        $retData = $ret->data();
        $message = $retData['message'] ?? '';

        if (!$message) {
            return new ResultError("Empty message in retData: " . print_r($retData, true));
        }

        return new ResultOK($message);
    }

    /** @return Result */
    public function systemMethodsList()
    {
        $this->req['method'] = 'system.methods.list';
        unset($this->req['params']);

        $ret = $this->request();
        if ($ret->error()) {
            return $ret;
        }
        return new ResultOK($ret->data()['methods']);
    }

    /** @return bool */
    public function a2iCampaignExists(string $campaign)
    {
        $rslt = $this->a2iCampaignList();
        if ($rslt->error()) {
            return false;
        }
        $campaigns = $rslt->data();
        return in_array($campaign, $campaigns);
    }

    /** @return Result */
    public function a2iCampaignList()
    {
        $this->req['method'] = 'a2i.campaign.list';
        unset($this->req['params']);

        $ret = $this->request();
        if ($ret->error()) {
            return $ret;
        }

        $camps = $ret->data()['campaigns'];
        return new ResultOK($camps);
    }

    /** @return Result */
    public function a2iCampaignCreate(string $campaign, array $campSettings)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        if (!count($campSettings)) {
            return new ResultError("No campSettings given");
        }
        $this->req['method'] = 'a2i.campaign.create';
        $this->req['params'] = [ 'name' => $campaign, 'settings' => $campSettings, ];

        $ret = $this->request();
        if ($ret->error()) {
            return $ret;
        }
        if ($ret->data()['response'] == 'success') {
            return new ResultOK($ret->data()['message']);
        }
        return new ResultError($ret->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignUpdate(string $campaign, array $campSettings)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        if (!count($campSettings)) {
            return new ResultError("No campSettings given");
        }
        $this->req['method'] = 'a2i.campaign.update';
        $this->req['params'] = [ 'name' => $campaign, 'settings' => $campSettings, ];

        $ret = $this->request();
        if ($ret->error()) {
            return $ret;
        }
        if ($ret->data()['response'] == 'success') {
            return new ResultOK($ret->data()['message']);
        }
        return new ResultError($ret->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignDrop(string $campaign)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        $this->req['method'] = 'a2i.campaign.drop';
        $this->req['params'] = [ 'name' => $campaign, ];

        $ret = $this->request();
        if ($ret->error()) {
            return $ret;
        }
        if ($ret->data()['response'] == 'success') {
            return new ResultOK($ret->data()['message']);
        }
        return new ResultError($ret->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignStart(string $campaign)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        $this->req['method'] = 'a2i.campaign.start';
        $this->req['params'] = [ 'name' => $campaign, ];

        $ret = $this->request();
        if ($ret->error()) {
            return $ret;
        }

        if ($ret->data()['response'] == 'success') {
            return new ResultOK($ret->data()['message']);
        }
        return new ResultError($ret->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignStop(string $campaign)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        $this->req['method'] = 'a2i.campaign.stop';
        $this->req['params'] = [ 'name' => $campaign, ];

        $ret = $this->request();
        if ($ret->error()) {
            return $ret;
        }
        if ($ret->data()['response'] == 'success') {
            return new ResultOK($ret->data()['message']);
        }
        return new ResultError($ret->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignStatus(string $campaign)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        $this->req['method'] = 'a2i.campaign.status';
        $this->req['params'] = [ 'name' => $campaign, ];

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'success') {
            return new ResultOK($rslt->data()['status']);
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignsInfo(array $campaigns = [])
    {
        $this->req['method'] = 'a2i.campaigns.info';
        $this->req['params'] = [ 'campaigns' => $campaigns, ];

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'success') {
            return new ResultOK($rslt->data());
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignSettings(string $campaign)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        $this->req['method'] = 'a2i.campaign.settings';
        $this->req['params'] = [ 'name' => $campaign, ];

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'success') {
            return new ResultOK($rslt->data()['settings']);
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignLog(string $campaign, string $dateFrom = '', string $dateTo = '', $limit = '')
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        $this->req['method'] = 'a2i.campaign.log';
        $this->req['params'] = [ 'name' => $campaign, ];

        if ($dateFrom) {
            $this->req['params']['dateFrom'] = $dateFrom;
        }

        if ($dateTo) {
            $this->req['params']['dateTo'] = $dateTo;
        }

        if ($limit) {
            $this->req['params']['limit'] = $limit;
        }

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'success') {
            return new ResultOK($rslt->data()['log']);
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignDataGet(string $campaign)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        $this->req['method'] = 'a2i.campaign.data.get';
        $this->req['params'] = [ 'name' => $campaign, ];

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'success') {
            return new ResultOK($rslt->data()['data']);
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignNumsList(string $campaign)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }
        $rslt = $this->a2iCampaignDataGet($campaign);
        if ($rslt->error()) {
            return $rslt;
        }
        return new ResultOK(array_keys($rslt->data()));
    }

    /** @return Result */
    public function a2iCampaignDataAdd(string $campaign, array $data)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        if (!count($data)) {
            return new ResultError("No data given");
        }

        $this->req['method'] = 'a2i.campaign.data.add';
        $this->req['params'] = [ 'name' => $campaign, 'data' => $data ];

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'success') {
            return new ResultOK($rslt->data()['message']);
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignDataCut(string $campaign, array $data)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        if (!count($data)) {
            return new ResultError("No data given");
        }
        $this->req['method'] = 'a2i.campaign.data.cut';
        $this->req['params'] = [ 'name' => $campaign, 'data' => $data ];

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'success') {
            return new ResultOK($rslt->data()['message']);
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function a2iCampaignTtsGet(string $campaign, string $number)
    {
        if (!$campaign) {
            return new ResultError("Empty campaign");
        }

        if (!$number) {
            return new ResultError("Empty number");
        }
        $this->req['method'] = 'a2i.campaign.tts.get';
        $this->req['params'] = [ 'name' => $campaign, 'number' => $number, ];

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'success') {
            return new ResultOK($rslt->data()['tts']);
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function callOriginate(array $destination, array $bridgeTarget, array $extraData = [])
    {
        if (!count($destination)) {
            return new ResultError("destination");
        }
        if (!count($bridgeTarget)) {
            return new ResultError("bridgeTarget");
        }

        $this->req['method'] = 'call.originate';
        $this->req['params'] = [
            'destination' => $destination,
            'bridge-target' => $bridgeTarget,
            'extra-data' => $extraData
        ];

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        $rsltData = $rslt->data();
        $response = $rsltData['response'] ?? '';

        if (!$response) {
            return new ResultError("No 'response' field in rsltData: " .print_r($rsltData, true));
        }

        if ($response == 'success') {
            $rsltMsg = $rsltData['result'] ?? '';
            if ($rsltMsg) {
                return new ResultOK($rsltMsg);
            }
            return new ResultError("No 'result' field in rsltData: " .print_r($rsltData, true));
        }

        return new ResultError("Unknown error. rsltData: " .print_r($rsltData, true));
    }

    /** @return Result */
    public function callInfo(string $callId)
    {
        if (!$callId) {
            return new ResultError("Empty callId");
        }

        $this->req['method'] = 'call.info';
        $this->req['params'] = [ 'callid' => $callId, ];

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'success') {
            return new ResultOK($rslt->data()['result']);
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function callsInfo(array $callIds, array $fields = [])
    {
        if (empty($callIds)) {
            return new ResultError("Empty callIds array");
        }

        $this->req['method'] = 'calls.info';
        $this->req['params'] = [ 'callids' => $callIds, ];
        if ($fields) {
            $this->req['params']['fields'] = $fields;
        }

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'success') {
            return new ResultOK($rslt->data()['result']);
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function queueMemberPause(string $member, string $queue, string $reason)
    {
        if (!$member) {
            return new ResultError("Empty member");
        }
        if (!$queue) {
            return new ResultError("Empty queue");
        }
        if (!$reason) {
            return new ResultError("Empty reason");
        }

        $this->req['method'] = 'queue.member.pause';
        $this->req['params'] = [ 'member' => $member, 'queue' => $queue, 'reason' => $reason ];

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'Success') {
            return new ResultOK($rslt->data()['message']);
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function queueMemberUnpause(string $member, string $queue)
    {
        if (!$member) {
            return new ResultError("Empty member");
        }
        if (!$queue) {
            return new ResultError("Empty queue");
        }

        $this->req['method'] = 'queue.member.unpause';
        $this->req['params'] = [ 'member' => $member, 'queue' => $queue, ];

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'Success') {
            return new ResultOK($rslt->data()['message']);
        }
        return new ResultError($rslt->data()['message']);
    }

    /** @return Result */
    public function queueMemberStatus(string $member, string $reason = '')
    {
        if (!$member) {
            return new ResultError("Empty member");
        }

        $this->req['method'] = 'queue.member.status';
        $this->req['params'] = [ 'member' => $member, ] ;
        if ($reason) {
            $this->req['params']['reason'] = $reason;
        }

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        if ($rslt->data()['response'] == 'success') {
            return new ResultOK($rslt->data()['status']);
        }
        return new ResultError($rslt->data()['message']);
    }

    public function eventsList()
    {
        $this->req['method'] = 'events.list';
        unset($this->req['params']);

        $ret = $this->request();
        if ($ret->error()) {
            return $ret;
        }

        return new ResultOK($ret->data()['events']);
    }

    public function eventsEmit(array $evtData, string $evtName = '')
    {
        $this->req['method'] = 'events.emit';
        $this->req['params'] = ['event-data' => $evtData ];
        if ($evtName) {
            $this->req['params']['event-name'] = $evtName;
        }

        $rslt = $this->request();
        if ($rslt->error()) {
            return $rslt;
        }

        $rsltData = $rslt->data();
        $rsltResponse = $rsltData['response'] ?? '';
        $rsltMessage = $rsltData['message'] ?? '';

        if ($rsltResponse == 'success') {
            return new ResultOK($rsltMessage);
        }

        if ($rsltResponse && $rsltMessage) { // not success, but not empty
            return new ResultError($rsltMessage);
        }

        return new ResultError("Invalid response rsltData: " . print_r($rsltData, true));
    }

    /**
     * @return Result
     */
    public function request(array $request = [])
    {
        if (empty($request)) {
            $request = $this->req;
        }

        $jsonRequest = json_encode($request, JSON_PRETTY_PRINT);
        $data = ['request' => $jsonRequest];

        // use key 'http' even if you send the request to https://...
        $options = ['http' => [
                        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                        'method'  => 'POST',
                        'content' => http_build_query($data)
                   ]
            ];
        $context = stream_context_create($options);
        $result = @file_get_contents($this->url, false, $context);

        if ($result === false) {
            return new ResultError("Could not get answer");
        }

        $ans = @json_decode($result, true);
        if ($ans === null) {
            return new ResultError("Could not decode answer: " . $result);
        }

        if (isset($ans['error']['message'])) {
            return new ResultError("Bad request: " . $ans['error']['message']);
        }

        if (!isset($ans['result']['response'])) {
            return new ResultError("Bad request: " . $result);
        }

        if (!$ans['result']['response'] == 'success') {
            return new ResultError("Bad request: " . $result);
        }

        return new ResultOK($ans['result']);
    }
}
